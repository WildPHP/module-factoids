<?php
/**
 * Created by PhpStorm.
 * User: rick2
 * Date: 24-4-2017
 * Time: 13:09
 */

namespace WildPHP\Modules\Factoids;


use WildPHP\Core\Channels\Channel;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\Commands\CommandHelp;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\Connection\IRCMessages\RPL_ENDOFNAMES;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\DataStorage\DataStorage;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Users\User;

class Factoids
{
	use ContainerTrait;

	protected $factoidPools = [];

	public function __construct(ComponentContainer $container)
	{
		EventEmitter::fromContainer($container)->on('irc.command', [$this, 'displayFactoid']);
		EventEmitter::fromContainer($container)->on('irc.line.in.366', [$this, 'createPoolForChannel']);
		register_shutdown_function([$this, 'saveFactoidData']);
		$this->loadFactoidData();

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Adds a new factoid to the current (or other) channel.');
		$commandHelp->addPage('Usage #1: addfactoid [key] [string]');
		$commandHelp->addPage('Usage #2: addfactoid [#channel] [key] [string]');
		$commandHelp->addPage('Usage #3: addfactoid global [key] [string]');
		CommandHandler::fromContainer($container)->registerCommand('addfactoid', [$this, 'addfactoidCommand'], $commandHelp, 2, -1, 'addfactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Removes a factoid from the current (or other) channel.');
		$commandHelp->addPage('Usage #1: removefactoid [key]');
		$commandHelp->addPage('Usage #2: removefactoid [#channel] [key]');
		$commandHelp->addPage('Usage #3: removefactoid global [key] [string]');
		CommandHandler::fromContainer($container)->registerCommand('removefactoid', [$this, 'removefactoidCommand'], $commandHelp, 1, 2, 'removefactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Edits a factoid to contain the specified string.');
		$commandHelp->addPage('Usage #1: editfactoid [key] [string]');
		$commandHelp->addPage('Usage #2: editfactoid [#channel] [key] [string]');
		$commandHelp->addPage('Usage #3: editfactoid global [key] [string]');
		CommandHandler::fromContainer($container)->registerCommand('editfactoid', [$this, 'editfactoidCommand'], $commandHelp, 2, -1, 'editfactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Lists factoids in a given channel.');
		$commandHelp->addPage('Usage #1: listfactoids');
		$commandHelp->addPage('Usage #2: listfactoids [#channel]');
		$commandHelp->addPage('Usage #3: listfactoids global');
		CommandHandler::fromContainer($container)->registerCommand('listfactoids', [$this, 'listfactoidsCommand'], $commandHelp, 0, 1);

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Moves a factoid between channels.');
		$commandHelp->addPage('Usage #1: movefactoid [key] [#target_channel]');
		$commandHelp->addPage('Usage #2: movefactoid [key] [#source_channel] [#target_channel]');
		$commandHelp->addPage('Usage #3: movefactoid [key] global [#target_channel] (or reverse)');
		CommandHandler::fromContainer($container)->registerCommand('movefactoid', [$this, 'movefactoidCommand'], $commandHelp, 2, 3, 'movefactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Renames a factoid.');
		$commandHelp->addPage('Usage #1: renamefactoid [key] [new name]');
		$commandHelp->addPage('Usage #2: renamefactoid [#channel] [key] [new name]');
		$commandHelp->addPage('Usage #3: renamefactoid global [key] [new name]');
		CommandHandler::fromContainer($container)->registerCommand('renamefactoid', [$this, 'renamefactoidCommand'], $commandHelp, 2, 3, 'renamefactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Displays info about a factoid.');
		$commandHelp->addPage('Usage #1: factoidinfo [key]');
		$commandHelp->addPage('Usage #2: factoidinfo [#channel] [key]');
		$commandHelp->addPage('Usage #3: factoidinfo global [key]');
		CommandHandler::fromContainer($container)->registerCommand('factoidinfo', [$this, 'factoidinfoCommand'], $commandHelp, 1, 2);

		$this->setContainer($container);
	}

	public function loadFactoidData()
	{
		$dataStorage = new DataStorage('factoidStorage');
		$data = $dataStorage->getAll();

		foreach ($data as $channel => $factoids)
		{
			$pool = new FactoidPool('\WildPHP\Modules\Factoids\Factoid');
			$this->factoidPools[$channel] = $pool;

			$pool->populateFromSavedArray($factoids);
		}
	}

	public function saveFactoidData()
	{
		$dataStorage = new DataStorage('factoidStorage');
		$dataStorage->flush();

		foreach ($this->factoidPools as $channel => $pool)
		{
			$dataStorage->set($channel, $pool->toSaveableArray());
		}
	}

	/**
	 * @param RPL_ENDOFNAMES $incomingIrcMessage
	 * @param Queue $queue
	 */
	public function createPoolForChannel(RPL_ENDOFNAMES $incomingIrcMessage, Queue $queue)
	{
		$channel = $incomingIrcMessage->getChannel();

		if (!$this->poolExistsForChannelByString($channel))
			$this->factoidPools[$channel] = new FactoidPool('\WildPHP\Modules\Factoids\Factoid');

		if (!$this->poolExistsForChannelByString('global'))
			$this->factoidPools['global'] = new FactoidPool('\WildPHP\Modules\Factoids\Factoid');
	}

	/**
	 * @param Channel $channel
	 * @return bool
	 */
	public function poolExistsForChannel(Channel $channel): bool
	{
		return array_key_exists($channel->getName(), $this->factoidPools);
	}

	/**
	 * @param string $channel
	 * @return bool
	 */
	public function poolExistsForChannelByString(string $channel): bool
	{
		return array_key_exists($channel, $this->factoidPools);
	}

	/**
	 * @param Channel $channel
	 * @return bool|FactoidPool
	 */
	public function getPoolForChannel(Channel $channel)
	{
		if (!$this->poolExistsForChannel($channel))
			return false;

		return $this->factoidPools[$channel->getName()];
	}

	/**
	 * @param string $channel
	 * @return bool|FactoidPool
	 */
	public function getPoolForChannelByString(string $channel)
	{
		if (!$this->poolExistsForChannelByString($channel))
			return false;

		return $this->factoidPools[$channel];
	}

	/**
	 * @param string $command
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function displayFactoid(string $command, Channel $source, User $user, $args, ComponentContainer $container)
	{
		if (CommandHandler::fromContainer($container)->getCommandDictionary()->keyExists($command))
			return;

		$key = $command;
		$target = $source->getName();

		$globalFactoidPool = $this->getPoolForChannelByString('global');
		$factoidPool = $this->getPoolForChannelByString($target);

		$factoid = $factoidPool->find(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});

		if (empty($factoid))
			$factoid = $globalFactoidPool->find(function (Factoid $factoid) use ($key)
			{
				return $key == $factoid->getName();
			});

		if (empty($factoid))
			return;

		$at = array_shift($args);
		$tnickname = array_shift($args);
		$nickname = (!empty($tnickname) && $at == '@') ? $tnickname : '';

		$contents = str_ireplace([
			'$nick',
			'$channel',
		], [
			$user->getNickname(),
			$source->getName()
		], $factoid->getContents());

		$message = !empty($nickname) ? $nickname . ': ' : '';
		$message .= $contents;

		Queue::fromContainer($container)->privmsg($source->getName(), $message);
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function addfactoidCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$prefix = Configuration::fromContainer($container)->get('serverConfig.chantypes')->getValue();
		if ($args[0] == 'global' || Channel::isValidName($args[0], $prefix))
		{
			$target = array_shift($args);
		}

		$key = array_shift($args);
		$string = implode(' ', $args);

		if (CommandHandler::fromContainer($this->getContainer())->getCommandDictionary()->keyExists($key))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', a command with the same name already exists.');
			return;
		}

		$factoidPool = $this->getPoolForChannelByString($target);

		$testFactoidExists = $factoidPool->find(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});

		if (!empty($testFactoidExists))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', a factoid with the same name already exists in this channel.');
			return;
		}

		$factoid = new Factoid();
		$factoid->setName($key);
		$factoid->setContents($string);
		$factoid->setCreatedByAccount($user->getIrcAccount());
		$factoid->setCreatedTime(time());
		$factoidPool->add($factoid);

		Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', successfully created factoid with key "' . $key . '" for target "' . $target . '".');
		$this->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function removefactoidCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$prefix = Configuration::fromContainer($container)->get('serverConfig.chantypes')->getValue();
		if ($args[0] == 'global' || Channel::isValidName($args[0], $prefix))
		{
			$target = array_shift($args);
		}

		$key = array_shift($args);

		$factoidPool = $this->getPoolForChannelByString($target);

		$factoid = $factoidPool->remove(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});

		if (empty($factoid))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');
			return;
		}

		Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', successfully removed factoid with key "' . $key . '" for target "' . $target . '".');
		$this->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function editfactoidCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$prefix = Configuration::fromContainer($container)->get('serverConfig.chantypes')->getValue();
		if ($args[0] == 'global' || Channel::isValidName($args[0], $prefix))
		{
			$target = array_shift($args);
		}

		$key = array_shift($args);
		$message = implode(' ', $args);

		$factoidPool = $this->getPoolForChannelByString($target);

		$factoid = $factoidPool->find(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});

		if (empty($factoid))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');
			return;
		}

		$factoid->setContents($message);
		$factoid->setEditedTime(time());
		$factoid->setEditedByAccount($user->getIrcAccount());

		Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', successfully edited factoid with key "' . $key . '" for target "' . $target . '".');
		$this->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function listfactoidsCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$prefix = Configuration::fromContainer($container)->get('serverConfig.chantypes')->getValue();
		if (!empty($args[0]) && ($args[0] == 'global' || Channel::isValidName($args[0], $prefix)))
		{
			$target = array_shift($args);
		}

		$factoidPool = $this->getPoolForChannelByString($target);
		$factoids = $factoidPool->toArray();

		if (empty($factoids))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), 'There are no factoids for target "' . $target . '"');
			return;
		}

		$names = [];
		foreach ($factoids as $factoid)
			$names[] = $factoid->getName();

		$pieces = array_chunk($names, 10);
		foreach ($pieces as $piece)
			Queue::fromContainer($container)->privmsg($source->getName(), 'Factoids for target "' . $target . '": ' . implode(', ', $piece));
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function movefactoidCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$key = array_shift($args);
		$target = count($args) == 1 ? $source->getName() : array_shift($args);
		$newTarget = array_shift($args);

		$factoidPool = $this->getPoolForChannelByString($target);
		$newFactoidPool = $this->getPoolForChannelByString($newTarget);

		if (empty($newFactoidPool))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), 'The target "' . $newTarget . '" does not exist or is not loaded.');
			return;
		}

		$factoid = $factoidPool->find(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});

		if (empty($factoid))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');
			return;
		}

		$factoidPool->remove(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});
		$newFactoidPool->add($factoid);

		Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', successfully moved factoid with key "' . $key . '" from target "' . $target . '" to target "' . $newTarget . '".');
		$this->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function renamefactoidCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$prefix = Configuration::fromContainer($container)->get('serverConfig.chantypes')->getValue();
		if ($args[0] == 'global' || Channel::isValidName($args[0], $prefix))
		{
			$target = array_shift($args);
		}

		$key = array_shift($args);
		$newKey = array_shift($args);

		$factoidPool = $this->getPoolForChannelByString($target);

		$factoid = $factoidPool->find(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});

		if (empty($factoid))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');
			return;
		}

		$factoid->setName($newKey);
		Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', successfully renamed factoid with key "' . $key . '" to "' . $newKey . '" for target "' . $target . '".');
		$this->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function factoidinfoCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$prefix = Configuration::fromContainer($container)->get('serverConfig.chantypes')->getValue();
		if (!empty($args[0]) && ($args[0] == 'global' || Channel::isValidName($args[0], $prefix)))
		{
			$target = array_shift($args);
		}

		$key = array_shift($args);

		$factoidPool = $this->getPoolForChannelByString($target);
		$factoid = $factoidPool->find(function (Factoid $factoid) use ($key)
		{
			return $factoid->getName() == $key;
		});

		if (empty($factoid))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');
			return;
		}

		$name = $factoid->getName();
		$createdTime = date('d-m-Y H:i:s', $factoid->getCreatedTime());
		$createdBy = $factoid->getCreatedByAccount();
		$editedTime = date('d-m-Y H:i:s', $factoid->getEditedTime());
		$editedBy = $factoid->getEditedByAccount();

		$message = $name . ' (target ' . $target . '): Created by ' . $createdBy . ' (' . $createdTime . ')';
		if (!empty($editedBy))
			$message .= ', last edited by ' . $editedBy . ' (' . $editedTime . ')';
		$message .= '. Contents:';

		Queue::fromContainer($container)->privmsg($source->getName(), $message);
		Queue::fromContainer($container)->privmsg($source->getName(), $factoid->getContents());
	}
}