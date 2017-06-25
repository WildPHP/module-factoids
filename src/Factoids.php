<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Factoids;

use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use WildPHP\Modules\TGRelay\TgLog;
use WildPHP\Core\Channels\Channel;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\Commands\CommandHelp;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\Connection\IRCMessages\RPL_ENDOFNAMES;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\DataStorage\DataStorageFactory;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Users\User;
use WildPHP\Modules\TGRelay\TGCommandHandler;
use WildPHP\Modules\TGRelay\TGRelay;

class Factoids
{
	use ContainerTrait;

	protected $factoidPools = [];

	/**
	 * Factoids constructor.
	 *
	 * @param ComponentContainer $container
	 */
	public function __construct(ComponentContainer $container)
	{
		EventEmitter::fromContainer($container)
			->on('irc.command', [$this, 'displayFactoid']);
		EventEmitter::fromContainer($container)
			->on('irc.line.in.366', [$this, 'autoCreatePoolForChannel']);

		$this->loadFactoidData();

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Adds a new factoid to the current (or other) channel. Usage #1: addfactoid [key] [string]');
		$commandHelp->addPage('Usage #2: addfactoid [#channel] [key] [string]');
		$commandHelp->addPage('Usage #3: addfactoid global [key] [string]');
		CommandHandler::fromContainer($container)
			->registerCommand('addfactoid', [$this, 'addfactoidCommand'], $commandHelp, 2, -1, 'addfactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Removes a factoid from the current (or other) channel. Usage #1: removefactoid [key]');
		$commandHelp->addPage('Usage #2: removefactoid [#channel] [key]');
		$commandHelp->addPage('Usage #3: removefactoid global [key] [string]');
		CommandHandler::fromContainer($container)
			->registerCommand('removefactoid', [$this, 'removefactoidCommand'], $commandHelp, 1, 2, 'removefactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Edits a factoid to contain the specified string. Usage #1: editfactoid [key] [string]');
		$commandHelp->addPage('Usage #2: editfactoid [#channel] [key] [string]');
		$commandHelp->addPage('Usage #3: editfactoid global [key] [string]');
		CommandHandler::fromContainer($container)
			->registerCommand('editfactoid', [$this, 'editfactoidCommand'], $commandHelp, 2, -1, 'editfactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Lists factoids in a given channel. Usage #1: listfactoids');
		$commandHelp->addPage('Usage #2: listfactoids [#channel]');
		$commandHelp->addPage('Usage #3: listfactoids global');
		CommandHandler::fromContainer($container)
			->registerCommand('listfactoids', [$this, 'listfactoidsCommand'], $commandHelp, 0, 1);

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Moves a factoid between channels. Usage #1: movefactoid [key] [#target_channel]');
		$commandHelp->addPage('Usage #2: movefactoid [key] [#source_channel] [#target_channel]');
		$commandHelp->addPage('Usage #3: movefactoid [key] global [#target_channel] (or reverse)');
		CommandHandler::fromContainer($container)
			->registerCommand('movefactoid', [$this, 'movefactoidCommand'], $commandHelp, 2, 3, 'movefactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Renames a factoid. Usage #1: renamefactoid [key] [new name]');
		$commandHelp->addPage('Usage #2: renamefactoid [#channel] [key] [new name]');
		$commandHelp->addPage('Usage #3: renamefactoid global [key] [new name]');
		CommandHandler::fromContainer($container)
			->registerCommand('renamefactoid', [$this, 'renamefactoidCommand'], $commandHelp, 2, 3, 'renamefactoid');

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Displays info about a factoid. Usage #1: factoidinfo [key]');
		$commandHelp->addPage('Usage #2: factoidinfo [#channel] [key]');
		$commandHelp->addPage('Usage #3: factoidinfo global [key]');
		CommandHandler::fromContainer($container)
			->registerCommand('factoidinfo', [$this, 'factoidinfoCommand'], $commandHelp, 1, 2);

		EventEmitter::fromContainer($container)->on('telegram.commands.add', function (TGCommandHandler $commandHandler)
		{
			$commandHandler->registerCommand('factoid', [$this, 'factoidTGCommand'], null, 0, 3);
			$commandHandler->registerCommand('f', [$this, 'factoidTGCommand'], null, 0, 3);
		});

		$this->setContainer($container);
	}

	public function loadFactoidData()
	{
		$dataStorage = DataStorageFactory::getStorage('factoidStorage');
		$data = $dataStorage->getAll();

		foreach ($data as $factoidData)
		{
			$channel = $factoidData['channel'];
			$pool = new FactoidPool();
			$this->factoidPools[$channel] = $pool;

			$pool->populateFromSavedArray($factoidData['pool']);
		}
	}

	public function saveFactoidData()
	{
		$dataStorage = DataStorageFactory::getStorage('factoidStorage');
		$dataStorage->flush();

		$i = 0;
		/**
		 * @var string $channel
		 * @var FactoidPool $pool
		 */
		foreach ($this->factoidPools as $channel => $pool)
		{
			$i++;
			$array = ['channel' => $channel, 'pool' => $pool->toSaveableArray()];
			$dataStorage->set($i, $array);
		}
	}

	/**
	 * @param RPL_ENDOFNAMES $incomingIrcMessage
	 */
	public function autoCreatePoolForChannel(RPL_ENDOFNAMES $incomingIrcMessage)
	{
		$channel = $incomingIrcMessage->getChannel();

		if (!$this->poolExistsForChannelByString($channel))
			$this->factoidPools[$channel] = new FactoidPool();

		if (!$this->poolExistsForChannelByString('global'))
			$this->factoidPools['global'] = new FactoidPool();
	}

	/**
	 * @param Channel $channel
	 *
	 * @return bool
	 */
	public function poolExistsForChannel(Channel $channel): bool
	{
		return array_key_exists($channel->getName(), $this->factoidPools);
	}

	/**
	 * @param string $channel
	 *
	 * @return bool
	 */
	public function poolExistsForChannelByString(string $channel): bool
	{
		return array_key_exists($channel, $this->factoidPools);
	}

	/**
	 * @param Channel $channel
	 *
	 * @return FactoidPool
	 */
	public function getPoolForChannel(Channel $channel): FactoidPool
	{
		return $this->getPoolForChannelByString($channel->getName());
	}

	/**
	 * @param string $channel
	 *
	 * @return FactoidPool
	 */
	public function getPoolForChannelByString(string $channel): FactoidPool
	{
		if (!$this->poolExistsForChannelByString($channel))
			$this->factoidPools[$channel] = new FactoidPool();

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
		if (CommandHandler::fromContainer($container)
			->getCommandCollection()
			->offsetExists($command)
		)
			return;

		$nickname = count($args) > 0 ? $args[count($args) - 1] : '';

		$target = $source->getName();
		$factoid = $this->getFactoid($command, $target);

		if (!$factoid)
			return;

		$message = $this->parseFactoidMessage($factoid, $target, $user->getNickname(), $nickname);

		Queue::fromContainer($container)
			->privmsg($source->getName(), $message);
	}

	/**
	 * @param string $key
	 * @param string $target
	 *
	 * @return false|Factoid
	 */
	public function getFactoid(string $key, string $target = '')
	{
		if (!empty($target) && $this->poolExistsForChannelByString($target))
			$factoid = $this->getPoolForChannelByString($target)->findByKey($key);

		if (empty($factoid))
			$factoid = $this->getPoolForChannelByString('global')->findByKey($key);

		if (empty($factoid) || !($factoid instanceof Factoid))
			return false;

		return $factoid;
	}

	/**
	 * @param Factoid $factoid
	 * @param string $channel
	 * @param string $nickname
	 * @param string $senderNickname
	 *
	 * @return mixed
	 */
	public function parseFactoidMessage(Factoid $factoid, string $channel, string $senderNickname, string $nickname = '')
	{
		$msg = str_ireplace([
			'$nick',
			'$channel',
			'$sender'
		], [
			!empty($nickname) ? $nickname : $senderNickname,
			$channel,
			$senderNickname
		], $factoid->getContents());

		if (!empty($nickname) && !stripos($factoid->getContents(), '$nick'))
			$msg = $nickname . ': ' . $msg;

		return $msg;
	}


	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function addfactoidCommand(Channel $source, User $user, array $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$prefix = Configuration::fromContainer($container)
			->get('serverConfig.chantypes')
			->getValue();
		if ($args[0] == 'global' || Channel::isValidName($args[0], $prefix))
			$target = array_shift($args);

		$key = array_shift($args);
		$string = implode(' ', $args);

		if (CommandHandler::fromContainer($this->getContainer())
			->getCommandCollection()
			->offsetExists($key)
		)
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a command with the same name already exists.');

			return;
		}

		if (!$this->getPoolForChannelByString($target))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid pool for the given target does not exist.');

			return;
		}

		if ($this->getPoolForChannelByString($target)->findByKey($key))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with the same name already exists in this channel.');

			return;
		}

		$factoid = new Factoid();
		$factoid->setName($key);
		$factoid->setContents($string);
		$factoid->setCreatedByAccount($user->getIrcAccount());
		$factoid->setCreatedTime(time());
		$this->getPoolForChannelByString($target)->append($factoid);

		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully created factoid with key "' . $key . '" for target "' . $target . '".');

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
		$prefix = Configuration::fromContainer($container)
			->get('serverConfig.chantypes')
			->getValue();
		if ($args[0] == 'global' || Channel::isValidName($args[0], $prefix))
		{
			$target = array_shift($args);
		}

		$key = array_shift($args);

		if (!$this->getPoolForChannelByString($target))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid pool for the given target does not exist.');

			return;
		}

		$factoid = $this->getPoolForChannelByString($target)->findByKey($key);

		if (empty($factoid))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');

			return;
		}
		$this->getPoolForChannelByString($target)->remove($factoid);

		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully removed factoid with key "' . $key . '" for target "' . $target . '".');
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
		$prefix = Configuration::fromContainer($container)
			->get('serverConfig.chantypes')
			->getValue();
		if ($args[0] == 'global' || Channel::isValidName($args[0], $prefix))
		{
			$target = array_shift($args);
		}

		$key = array_shift($args);
		$message = implode(' ', $args);

		$factoid = $this->getPoolForChannelByString($target)->findByKey($key);

		if (empty($factoid))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');

			return;
		}

		$factoid->setContents($message);
		$factoid->setEditedTime(time());
		$factoid->setEditedByAccount($user->getIrcAccount());

		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully edited factoid with key "' . $key . '" for target "' . $target . '".');
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
		$prefix = Configuration::fromContainer($container)
			->get('serverConfig.chantypes')
			->getValue();
		if (!empty($args[0]) && ($args[0] == 'global' || Channel::isValidName($args[0], $prefix)))
		{
			$target = array_shift($args);
		}

		if (!($factoidPool = $this->getPoolForChannelByString($target)))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid pool for the given target does not exist.');

			return;
		}

		if (empty($factoidPool->values()))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), 'There are no factoids for target "' . $target . '"');

			return;
		}

		/** @var Factoid[] $factoids */
		$factoids = $factoidPool->values();

		$names = [];
		foreach ($factoids as $factoid)
			$names[] = $factoid->getName();

		$pieces = array_chunk($names, 10);
		foreach ($pieces as $piece)
			Queue::fromContainer($container)
				->privmsg($source->getName(), 'Factoids for target "' . $target . '": ' . implode(', ', $piece));
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
		$target = '';
		$newTarget = '';
		if (count($args) == 1)
		{
			$target = $source->getName();
			$this->findTargetForParams($args, $newTarget);
		}
		else
		{
			$this->findTargetForParams($args, $target);
			$this->findTargetForParams($args, $newTarget);
		}

		$factoidPool = $this->getPoolForChannelByString($target);
		$newFactoidPool = $this->getPoolForChannelByString($newTarget);

		if (empty($factoidPool) || empty($newFactoidPool))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), 'The target "' . $target . '" or "' . $newTarget . '" does not exist or is not loaded.');

			return;
		}

		$factoid = $factoidPool->findByKey($key);
		if (empty($factoid) || $newFactoidPool->findByKey($key))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist, or already exists in the destination.');

			return;
		}

		$factoidPool->remove($factoid);
		$newFactoidPool->append($factoid);

		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully moved factoid with key "' . $key . '" from target "' . $target . '" to target "' . $newTarget .
				'".');
		$this->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param array $args
	 * @param ComponentContainer $container
	 */
	public function renamefactoidCommand(Channel $source, User $user, array $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$this->findTargetForParams($args, $target);

		$key = array_shift($args);
		$newKey = array_shift($args);

		$factoid = $this->getPoolForChannelByString($target)->findByKey($key);
		if (empty($factoid))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');

			return;
		}

		if ($this->getPoolForChannelByString($target)->findByKey($newKey))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name already exists.');

			return;
		}

		$factoid->setName($newKey);
		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully renamed factoid with key "' . $key . '" to "' . $newKey . '" for target "' . $target . '".');
		$this->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param array $args
	 * @param ComponentContainer $container
	 */
	public function factoidinfoCommand(Channel $source, User $user, array $args, ComponentContainer $container)
	{
		$target = $source->getName();
		$this->findTargetForParams($args, $target);

		$key = array_shift($args);

		$factoid = $this->getPoolForChannelByString($target)->findByKey($key);

		if (empty($factoid))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');

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

		Queue::fromContainer($container)
			->privmsg($source->getName(), $message);
		Queue::fromContainer($container)
			->privmsg($source->getName(), $factoid->getContents());
	}

	/**
	 * @param TgLog $telegram
	 * @param mixed $chat_id
	 * @param array $args
	 * @param string $channel
	 * @param string $username
	 */
	public function factoidTGCommand(TgLog $telegram, $chat_id, array $args, string $channel, string $username)
	{
		if (empty($args))
			return;

		$key = array_shift($args);
		$nickname = count($args) > 0 ? $args[count($args) - 1] : '';

		$factoid = $this->getFactoid($key, $channel);
		if (empty($factoid))
		{
			$sendMessage = new SendMessage();
			$sendMessage->chat_id = $chat_id;
			$sendMessage->text = 'No such factoid exists.';
			$telegram->performApiRequest($sendMessage);

			return;
		}
		$message = $this->parseFactoidMessage($factoid, $channel, $username, $nickname);
		$sendMessage = new SendMessage();
		$sendMessage->chat_id = $chat_id;
		$sendMessage->text = $message;
		$telegram->performApiRequest($sendMessage);

		if (!empty($channel))
		{
			Queue::fromContainer($this->getContainer())
				->privmsg($channel, '[TG] Factoid "' . $key . '" requested by ' . TGRelay::colorNickname($username));
			Queue::fromContainer($this->getContainer())
				->privmsg($channel, $message);
		}
	}

	/**
	 * @param array $args
	 * @param string $target
	 */
	public function findTargetForParams(array &$args, string &$target)
	{
		$prefix = Configuration::fromContainer($this->getContainer())
			->get('serverConfig.chantypes')
			->getValue();

		if (!empty($args[0]) && ($args[0] == 'global' || Channel::isValidName($args[0], $prefix)))
			$target = array_shift($args);
	}
}