<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Factoids;

use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use WildPHP\Core\Channels\Channel;
use WildPHP\Core\Channels\ValidChannelNameParameter;
use WildPHP\Core\Commands\Command;
use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\Commands\CommandHelp;
use WildPHP\Core\Commands\ParameterStrategy;
use WildPHP\Core\Commands\PredefinedStringParameter;
use WildPHP\Core\Commands\StringParameter;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\IRCMessages\RPL_ENDOFNAMES;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\Connection\TextFormatter;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Modules\BaseModule;
use WildPHP\Core\Users\User;
use WildPHP\Modules\TGRelay\TGCommandHandler;
use WildPHP\Modules\TGRelay\TgLog;

class Factoids extends BaseModule
{
	use ContainerTrait;

	protected $factoidPoolCollection = null;

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
		EventEmitter::fromContainer($container)
			->on('irc.line.in.376', [$this, 'registerCommands']);

		$this->factoidPoolCollection = new FactoidPoolCollection();
		$this->factoidPoolCollection->loadStoredFactoids();

		$this->setContainer($container);
		
		EventEmitter::fromContainer($container)->on('telegram.commands.add', function (TGCommandHandler $commandHandler)
		{
			$commandHandler->registerCommand('factoid', new Command(
				[$this, 'factoidTGCommand'],
				new ParameterStrategy(1, -1, [
					'key' => new StringParameter(),
					'parameters' => new StringParameter(),
				], true)
			), ['f']);
		});
	}

	public function registerCommands()
	{
		$container = $this->getContainer();
		$channelPrefix = Configuration::fromContainer($container)['serverConfig']['chantypes'];

		CommandHandler::fromContainer($container)->registerCommand('addfactoid', new Command(
			[$this, 'addfactoidCommand'],
			[
				new ParameterStrategy(3, -1, [
					'target' => new PredefinedStringParameter('global'),
					'key' => new StringParameter(),
					'contents' => new StringParameter()
				], true),
				new ParameterStrategy(3, -1, [
					'target' => new ValidChannelNameParameter($channelPrefix),
					'key' => new StringParameter(),
					'contents' => new StringParameter()
				], true),
				new ParameterStrategy(2, -1, [
					'key' => new StringParameter(),
					'contents' => new StringParameter()
				], true),
			],
			new CommandHelp([
				'Adds a new factoid to the current (or other) channel. Usage #1: addfactoid [key] [string]',
				'Usage #2: addfactoid [#channel] [key] [string]',
				'Usage #3: addfactoid global [key] [string]'
			]), 
			'addfactoid'
		), ['addfactoid', '+factoid', 'nf', '+f']);

		CommandHandler::fromContainer($container)->registerCommand('removefactoid', new Command(
			[$this, 'removefactoidCommand'],
			[
				new ParameterStrategy(2, 2, [
					'target' => new PredefinedStringParameter('global'),
					'key' => new StringParameter()
				]),
				new ParameterStrategy(2, 2, [
					'target' => new ValidChannelNameParameter($channelPrefix),
					'key' => new StringParameter(),
					'contents' => new StringParameter()
				]),
				new ParameterStrategy(1, 1, [
					'key' => new StringParameter()
				]),
			],
			new CommandHelp([
				'Removes a factoid from the current (or other) channel. Usage #1: removefactoid [key]',
				'Usage #2: removefactoid [#channel] [key]',
				'Usage #3: removefactoid global [key]'
			]),
			'removefactoid'
		), ['delfactoid', 'rmfactoid', '-factoid', 'rmf', '-f']);

		CommandHandler::fromContainer($container)->registerCommand('editfactoid', new Command(
			[$this, 'editfactoidCommand'],
			[
				new ParameterStrategy(3, -1, [
					'target' => new PredefinedStringParameter('global'),
					'key' => new StringParameter(),
					'contents' => new StringParameter()
				], true),
				new ParameterStrategy(3, -1, [
					'target' => new ValidChannelNameParameter($channelPrefix),
					'key' => new StringParameter(),
					'contents' => new StringParameter()
				], true),
				new ParameterStrategy(2, -1, [
					'key' => new StringParameter(),
					'contents' => new StringParameter()
				], true),
			],
			new CommandHelp([
				'Edits a factoid to contain the specified string. Usage #1: editfactoid [key] [string]',
				'Usage #2: editfactoid [#channel] [key] [string]',
				'Usage #3: editfactoid global [key] [string]'
			]),
			'editfactoid'
		), ['edf']);

		CommandHandler::fromContainer($container)->registerCommand('listfactoids', new Command(
			[$this, 'listfactoidsCommand'],
			[
				new ParameterStrategy(1, 1, [
					'target' => new PredefinedStringParameter('global')
				]),
				new ParameterStrategy(1, 1, [
					'target' => new ValidChannelNameParameter($channelPrefix)
				]),
				new ParameterStrategy(0, 0),
			],
			new CommandHelp([
				'Lists factoids in a given channel. Usage #1: listfactoids',
				'Usage #2: listfactoids [#channel]',
				'Usage #3: listfactoids global'
			])
		), ['lsfactoids', 'lsf']);

		CommandHandler::fromContainer($container)->registerCommand('movefactoid', new Command(
			[$this, 'movefactoidCommand'],
			[
				new ParameterStrategy(3, 3, [
					'key' => new StringParameter(),
					'source' => new ValidChannelNameParameter($channelPrefix),
					'target' => new PredefinedStringParameter('global')
				]),
				new ParameterStrategy(3, 3, [
					'key' => new StringParameter(),
					'source' => new PredefinedStringParameter('global'),
					'target' => new ValidChannelNameParameter($channelPrefix)
				]),
				new ParameterStrategy(3, 3, [
					'key' => new StringParameter(),
					'source' => new ValidChannelNameParameter($channelPrefix),
					'target' => new ValidChannelNameParameter($channelPrefix)
				]),
				new ParameterStrategy(2, 2, [
					'key' => new StringParameter(),
					'target' => new PredefinedStringParameter('global')
				]),
				new ParameterStrategy(2, 2, [
					'key' => new StringParameter(),
					'target' => new ValidChannelNameParameter($channelPrefix)
				])
			],
			new CommandHelp([
				'Moves a factoid between channels. Usage #1: movefactoid [key] [#target_channel]',
				'Usage #2: movefactoid [key] [#source_channel] [#target_channel]',
				'Usage #3: movefactoid [key] global [#target_channel] (or reverse)'
			])
		), ['mvfactoid', 'mvf']);

		CommandHandler::fromContainer($container)->registerCommand('renamefactoid', new Command(
			[$this, 'renamefactoidCommand'],
			[
				new ParameterStrategy(3, 3, [
					'target' => new PredefinedStringParameter('global'),
					'key' => new StringParameter(),
					'newkey' => new StringParameter()
				]),
				new ParameterStrategy(3, 3, [
					'target' => new ValidChannelNameParameter($channelPrefix),
					'key' => new StringParameter(),
					'newkey' => new StringParameter()
				]),
				new ParameterStrategy(2, 2, [
					'key' => new StringParameter(),
					'newkey' => new StringParameter()
				]),
			],
			new CommandHelp([
				'Renames a factoid. Usage #1: renamefactoid [key] [new name]',
				'Usage #2: renamefactoid [#channel] [key] [new name]',
				'Usage #3: renamefactoid global [key] [new name]'
			]),
			'renamefactoid'
		), ['rnfactoid', 'rnf']);

		CommandHandler::fromContainer($container)->registerCommand('factoidinfo', new Command(
			[$this, 'factoidinfoCommand'],
			[
				new ParameterStrategy(2, 2, [
					'target' => new PredefinedStringParameter('global'),
					'key' => new StringParameter()
				]),
				new ParameterStrategy(2, 2, [
					'target' => new ValidChannelNameParameter($channelPrefix),
					'key' => new StringParameter()
				]),
				new ParameterStrategy(1, 1, [
					'key' => new StringParameter()
				]),
			],
			new CommandHelp([
				'Displays info about a factoid. Usage #1: factoidinfo [key]',
				'Usage #2: factoidinfo [#channel] [key]',
				'Usage #3: factoidinfo global [key]'
			])
		), ['fi']);
	}

	/**
	 * @param RPL_ENDOFNAMES $incomingIrcMessage
	 */
	public function autoCreatePoolForChannel(RPL_ENDOFNAMES $incomingIrcMessage)
	{
		$channel = $incomingIrcMessage->getChannel();

		if (!$this->factoidPoolCollection->offsetExists($channel))
			$this->factoidPoolCollection[$channel] = new FactoidPool();

		if (!$this->factoidPoolCollection->offsetExists('global'))
			$this->factoidPoolCollection['global'] = new FactoidPool();
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
		if (CommandHandler::fromContainer($container)->getCommandCollection()->offsetExists($command))
			return;

		$nickname = count($args) > 0 ? implode(' ', array_filter($args, function (string $arg)
		{
			return $arg != '@';
		})) : '';

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
		if (!empty($target) && $this->factoidPoolCollection->offsetExists($target))
			$factoid = $this->factoidPoolCollection[$target]->findByKey($key);

		if (empty($factoid))
			$factoid = $this->factoidPoolCollection['global']->findByKey($key);

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
	    if (stripos($factoid->getContents(), '$noparse') !== false)
        {
            $msg = trim(str_ireplace('$noparse', '', $factoid->getContents()));
        }
        else
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
        }

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
		$target = !empty($args['target']) ? $args['target'] : $source->getName();

		$key = $args['key'];
		$string = $args['contents'];

		if (CommandHandler::fromContainer($this->getContainer())->getCommandCollection()->offsetExists($key))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a command with the same name already exists.');

			return;
		}

		if (!$this->factoidPoolCollection->offsetExists($target))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid pool for the given target does not exist.');

			return;
		}

		if ($this->factoidPoolCollection[$target]->findByKey($key))
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
		$this->factoidPoolCollection[$target]->append($factoid);

		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully created factoid with key "' . $key . '" for target "' . $target . '".');

		$this->factoidPoolCollection->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function removefactoidCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = !empty($args['target']) ? $args['target'] : $source->getName();

		$key = $args['key'];

		if (!isset($this->factoidPoolCollection[$target]))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid pool for the given target does not exist.');

			return;
		}

		/** @var Factoid $factoid */
		$factoid = $this->factoidPoolCollection[$target]->findByKey($key);

		if (empty($factoid))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');

			return;
		}
		$this->factoidPoolCollection[$target]->removeAll($factoid);

		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully removed factoid with key "' . $key . '" for target "' . $target . '".');
		$this->factoidPoolCollection->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function editfactoidCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = !empty($args['target']) ? $args['target'] : $source->getName();

		$key = $args['key'];
		$message = $args['contents'];

		/** @var Factoid $factoid */
		$factoid = $this->factoidPoolCollection[$target]->findByKey($key);

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
		$this->factoidPoolCollection->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function listfactoidsCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$target = !empty($args['target']) ? $args['target'] : $source->getName();

		if (!($factoidPool = $this->factoidPoolCollection[$target] ?? null))
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
	 * @param Channel $channel
	 * @param User $user
	 * @param $args
	 * @param ComponentContainer $container
	 */
	public function movefactoidCommand(Channel $channel, User $user, $args, ComponentContainer $container)
	{
		$source = !empty($args['source']) ? $args['source'] : $channel->getName();
		$target = $args['target'];
		$key = $args['key'];

		$factoidPool = $this->factoidPoolCollection[$source] ?? null;
		$newFactoidPool = $this->factoidPoolCollection[$target] ?? null;

		if (empty($factoidPool) || empty($newFactoidPool))
		{
			Queue::fromContainer($container)
				->privmsg($channel->getName(), 'The target "' . $target . '" or "' . $source . '" does not exist or is not loaded.');

			return;
		}

		$factoid = $factoidPool->findByKey($key);
		if (empty($factoid) || $newFactoidPool->findByKey($key))
		{
			Queue::fromContainer($container)
				->privmsg($channel->getName(), $user->getNickname() . ', a factoid with that name does not exist, or already exists in the destination.');

			return;
		}

		$factoidPool->removeAll($factoid);
		$newFactoidPool->append($factoid);

		Queue::fromContainer($container)
			->privmsg($channel->getName(),
				$user->getNickname() . ', successfully moved factoid with key "' . $key . '" from target "' . $source . '" to target "' . $target .
				'".');
		$this->factoidPoolCollection->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param array $args
	 * @param ComponentContainer $container
	 */
	public function renamefactoidCommand(Channel $source, User $user, array $args, ComponentContainer $container)
	{
		$target = !empty($args['target']) ? $args['target'] : $source->getName();

		$key = $args['key'];
		$newKey = $args['newkey'];

		/** @var Factoid $factoid */
		$factoid = $this->factoidPoolCollection[$target]->findByKey($key);
		if (empty($factoid))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name does not exist.');

			return;
		}

		if ($this->factoidPoolCollection[$target]->findByKey($newKey))
		{
			Queue::fromContainer($container)
				->privmsg($source->getName(), $user->getNickname() . ', a factoid with that name already exists.');

			return;
		}

		$factoid->setName($newKey);
		Queue::fromContainer($container)
			->privmsg($source->getName(),
				$user->getNickname() . ', successfully renamed factoid with key "' . $key . '" to "' . $newKey . '" for target "' . $target . '".');
		$this->factoidPoolCollection->saveFactoidData();
	}

	/**
	 * @param Channel $source
	 * @param User $user
	 * @param array $args
	 * @param ComponentContainer $container
	 */
	public function factoidinfoCommand(Channel $source, User $user, array $args, ComponentContainer $container)
	{
		$target = !empty($args['target']) ? $args['target'] : $source->getName();
		$key = $args['key'];

		/** @var Factoid $factoid */
		$factoid = $this->factoidPoolCollection[$target]->findByKey($key);

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

		$key = $args['key'];
		$nickname = count($args) > 0 ? end($args) : '';

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

		if (empty($channel))
		{
			$sendMessage = new SendMessage();
			$sendMessage->chat_id = $chat_id;
			$sendMessage->text = $message;
			$telegram->performApiRequest($sendMessage);
		}

		if (!empty($channel))
		{
			$privmsg = new PRIVMSG($channel, '[TG] Factoid "' . $key . '" requested by ' . TextFormatter::consistentStringColor($username));
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())
				->insertMessage($privmsg);
			Queue::fromContainer($this->getContainer())
				->privmsg($channel, $message);
		}
	}

	/**
	 * @return string
	 */
	public static function getSupportedVersionConstraint(): string
	{
		return '^3.0.0';
	}
}