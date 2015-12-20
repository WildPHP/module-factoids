<?php

/*
	WildPHP - a modular and easily extendable IRC bot written in PHP
	Copyright (C) 2015 WildPHP

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace WildPHP\Modules\Factoids;

use WildPHP\BaseModule;
use WildPHP\CoreModules\Connection\IrcDataObject;

class Factoids extends BaseModule
{
	/**
	 * @var GlobalStorage
	 */
	protected $globalStorage = null;

	/**
	 * @var array<string,ChannelStorage>
	 */
	protected $channelStorage = [];

	public function setup()
	{
		// TODO GET RID OF THIS
		include_once dirname(__FILE__) . '/GlobalStorage.php';
		include_once dirname(__FILE__) . '/ChannelStorage.php';

		$this->globalStorage = new GlobalStorage();

		if (file_exists(dirname(__FILE__) . '/factoids.json'))
			$this->loadJson(dirname(__FILE__) . '/factoids.json');

		$events = [
			'get' => 'irc.command',

			'create' => 'irc.command.setfactoid',
			'createGlobal' => 'irc.command.setglobalfactoid',

			'remove' => 'irc.command.delfactoid',
			'removeGlobal' => 'irc.command.delglobalfactoid',

			'listAll' => 'irc.command.lsfactoids',
			'listGlobals' => 'irc.command.lsglobalfactoids'
		];

		foreach ($events as $function => $event)
		{
			$this->getEventEmitter()->on($event, [$this, $function]);
		}

		register_shutdown_function(array($this, '__destruct'));
	}

	public function create($command, $params, IrcDataObject $object)
	{
		$nickname = $object->getMessage()['nick'];
		$channel = $object->getTargets()[0];

		$auth = $this->getModule('Auth');
		if (!$auth->nicknameIsTrusted($nickname))
			return;

		$pieces = explode(' ', $params, 2);

		if (count($pieces) < 2)
			return;

		$key = $pieces[0];
		$value = $pieces[1];

		$storage = $this->getChannelStorage($channel);
		$storage->add($key, $value, true);

		$connection = $this->getModule('Connection');
		$message = 'Factoid \'' . $key . '\' created for this channel.';
		$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
	}

	public function createGlobal($command, $params, IrcDataObject $object)
	{
		$nickname = $object->getMessage()['nick'];
		$channel = $object->getTargets()[0];

		$auth = $this->getModule('Auth');
		if (!$auth->nicknameIsTrusted($nickname))
			return;

		$pieces = explode(' ', $params, 1);

		if (count($pieces) < 2)
			return;

		$key = $pieces[0];
		$value = $pieces[1];

		$storage = $this->globalStorage;
		$storage->add($key, $value, true);

		$connection = $this->getModule('Connection');
		$message = 'Global factoid \'' . $key . '\' created.';
		$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
	}
	public function remove($command, $params, IrcDataObject $object)
	{
		$nickname = $object->getMessage()['nick'];
		$channel = $object->getTargets()[0];

		$auth = $this->getModule('Auth');
		if (!$auth->nicknameIsTrusted($nickname))
			return;

		$storage = $this->getChannelStorage($channel);

		$key = trim($params);

		if (!$storage->exists($key))
		{
			$connection = $this->getModule('Connection');
			$message = 'No such factoid with key \'' . $key . '\' for this channel. ' .
				'(are you trying to remove a global factoid?)';
			$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
			return;
		}

		$storage->remove($key);

		$connection = $this->getModule('Connection');
		$message = 'Successfully removed factoid with key \'' . $key . '\' for this channel.';
		$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
	}
	public function removeGlobal($command, $params, IrcDataObject $object)
	{
		$nickname = $object->getMessage()['nick'];
		$channel = $object->getTargets()[0];

		$auth = $this->getModule('Auth');
		if (!$auth->nicknameIsTrusted($nickname))
			return;

		$storage = $this->globalStorage;

		$key = trim($params);

		if (!$storage->exists($key))
		{
			$connection = $this->getModule('Connection');
			$message = 'No such global factoid with key \'' . $key . '\'. ' .
				'(are you trying to remove a factoid for a specific channel?)';
			$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
			return;
		}

		$storage->remove($key);

		$connection = $this->getModule('Connection');
		$message = 'Successfully removed global factoid with key \'' . $key . '\'.';
		$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
	}
	public function listAll($command, $params, IrcDataObject $object)
	{
		$channel = $object->getTargets()[0];
		$storage = $this->getChannelStorage($channel);

		$keys = array_keys($storage->getAll());

		$connection = $this->getModule('Connection');
		$message = 'Available factoids for this channel: ' . implode(', ', $keys);
		$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
	}

	public function listGlobals($command, $params, IrcDataObject $object)
	{
		$channel = $object->getTargets()[0];
		$storage = $this->globalStorage;

		$keys = array_keys($storage->getAll());

		$connection = $this->getModule('Connection');
		$message = 'Available global factoids: ' . implode(', ', $keys);
		$connection->write($connection->getGenerator()->ircPrivmsg($channel, $message));
	}

	protected function loadJson($file)
	{
		if (!file_exists($file))
			return;

		$decoded = json_decode(file_get_contents($file), true);

		if (!$decoded)
			return;

		$global = $decoded['global'];
		if ($global)
			$this->globalStorage->setAll($global);
		unset($decoded['global']);

		foreach ($decoded as $channel => $items)
		{
			$storage = $this->getChannelStorage($channel);
			$storage->setAll($items);
		}
	}

	protected function saveJson($file)
	{
		$structure = [];

		$structure['global'] = $this->globalStorage->getAll();

		foreach ($this->channelStorage as $channel => $storage)
		{
			$structure[$channel] = $storage->getAll();
		}

		file_put_contents($file, json_encode($structure));
	}

	protected function existsChannelStorage($channel)
	{
		return array_key_exists($channel, $this->channelStorage);
	}

	/**
	 * @param $channel
	 * @return ChannelStorage
	 */
	protected function getChannelStorage($channel)
	{
		if (!$this->existsChannelStorage($channel))
			$this->channelStorage[$channel] = new ChannelStorage();

		return $this->channelStorage[$channel];
	}

	public function get($command, $params, IrcDataObject $object)
	{
		$channel = $object->getTargets()[0];

		$channelStorage = $this->getChannelStorage($channel);

		$value = $channelStorage->get($command);
		if (!$value)
			$value = $this->globalStorage->get($command);

		if (!$value)
			return;

		$connection = $this->getModule('Connection');
		$connection->write($connection->getGenerator()->ircPrivmsg($channel, $value));
	}

	public function __destruct()
	{
		$this->saveJson(dirname(__FILE__) . '/factoids.json');
	}
}