<?php
/**
 * Created by PhpStorm.
 * User: rick2
 * Date: 24-4-2017
 * Time: 13:09
 */

namespace WildPHP\Modules\Factoids;


use Collections\Collection;

class FactoidPool extends Collection
{
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