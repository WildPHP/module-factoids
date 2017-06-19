<?php

/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
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