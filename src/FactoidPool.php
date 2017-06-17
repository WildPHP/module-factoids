<?php

/**
 * WildPHP - an advanced and easily extensible IRC bot written in PHP
 * Copyright (C) 2017 WildPHP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace WildPHP\Modules\Factoids;

use Collections\Collection;

class FactoidPool extends Collection
{
	public function __construct()
	{
		parent::__construct(Factoid::class);
	}

	/**
	 * @return array
	 */
	public function toSaveableArray(): array
	{
		/** @var Factoid[] $factoids */
		$factoids = $this->toArray();

		$array = [];
		foreach ($factoids as $factoid)
			$array[] = $factoid->toArray();

		return $array;
	}

	/**
	 * @param string $key
	 *
	 * @return false|Factoid
	 */
	public function findByKey(string $key)
	{
		return $this->find(function (Factoid $factoid) use ($key)
		{
			return $key == $factoid->getName();
		});
	}

	/**
	 * @param array $array
	 */
	public function populateFromSavedArray(array $array)
	{
		foreach ($array as $factoid)
		{
			if (array_keys($factoid) != ['name', 'createdTime', 'createdByAccount', 'editedTime', 'editedByAccount', 'locked', 'contents'])
				continue;

			$obj = new Factoid();
			$obj->setName($factoid['name']);
			$obj->setCreatedTime($factoid['createdTime']);
			$obj->setCreatedByAccount($factoid['createdByAccount']);
			$obj->setEditedTime($factoid['editedTime']);
			$obj->setEditedByAccount($factoid['editedByAccount']);
			$obj->setLocked($factoid['locked']);
			$obj->setContents($factoid['contents']);
			$this->add($obj);
		}
	}
}