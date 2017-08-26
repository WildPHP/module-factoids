<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\Factoids;


use ValidationClosures\Types;
use WildPHP\Core\DataStorage\DataStorageFactory;
use Yoshi2889\Collections\Collection;

class FactoidPoolCollection extends Collection
{
	public function __construct(array $initialValues = [])
	{
		parent::__construct(Types::instanceof(FactoidPool::class), $initialValues);
	}

	public function loadStoredFactoids()
	{
		$dataStorage = DataStorageFactory::getStorage('factoidStorage');
		$data = $dataStorage->getAll();

		foreach ($data as $factoidData)
		{
			$channel = $factoidData['channel'];
			$pool = new FactoidPool();
			$pool->populateFromSavedArray($factoidData['pool']);

			$this->offsetSet($channel, $pool);
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
		foreach ($this->getArrayCopy() as $channel => $pool)
		{
			$i++;
			$array = ['channel' => $channel, 'pool' => $pool->toSaveableArray()];
			$dataStorage->set($i, $array);
		}
	}
}