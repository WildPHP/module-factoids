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

class GlobalStorage
{
	/**
	 * @var array<string,string>
	 */
	protected $storage = [];

	/**
	 * @param string $key
	 * @param string $value
	 * @param bool $overwrite
	 *
	 * @return bool
	 */
	public function add($key, $value, $overwrite = false)
	{
		if (!is_string($key) || !is_string($value))
			return false;

		if ($this->exists($key) && !$overwrite)
			return false;

		$this->storage[$key] = $value;
		return true;
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function exists($key)
	{
		return array_key_exists($key, $this->storage);
	}

	/**
	 * @param string $key
	 *
	 * @return bool
	 */
	public function remove($key)
	{
		if (!$this->exists($key))
			return false;

		unset($this->storage[$key]);
		return true;
	}

	/**
	 * @param string $key
	 * @return mixed False on failure, string on success.
	 */
	public function get($key)
	{
		if (!$this->exists($key))
			return false;

		return $this->storage[$key];
	}

	/**
	 * @return array<string,string>
	 */
	public function getAll()
	{
		return $this->storage;
	}

	/**
	 * @param array<string,string>
	 */
	public function setAll(array $items)
	{
		foreach ($items as $key => $value)
		{
			$this->add($key, $value);
		}
	}
}