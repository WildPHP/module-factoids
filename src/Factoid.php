<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

/**
 * Created by PhpStorm.
 * User: rick2
 * Date: 24-4-2017
 * Time: 13:08
 */

namespace WildPHP\Modules\Factoids;


class Factoid
{
	/**
	 * @var string
	 */
	protected $name = '';

	/**
	 * @var int
	 */
	protected $createdTime = 0;

	/**
	 * @var int
	 */
	protected $editedTime = 0;

	/**
	 * @var string
	 */
	protected $createdByAccount = '';

	/**
	 * @var string
	 */
	protected $editedByAccount = '';

	/**
	 * @var bool
	 */
	protected $locked = false;

	/**
	 * @var string
	 */
	protected $contents = '';

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @param string $name
	 */
	public function setName(string $name)
	{
		$this->name = $name;
	}

	/**
	 * @return int
	 */
	public function getCreatedTime(): int
	{
		return $this->createdTime;
	}

	/**
	 * @param int $createdTime
	 */
	public function setCreatedTime(int $createdTime)
	{
		$this->createdTime = $createdTime;
	}

	/**
	 * @return int
	 */
	public function getEditedTime(): int
	{
		return $this->editedTime;
	}

	/**
	 * @param int $editedTime
	 */
	public function setEditedTime(int $editedTime)
	{
		$this->editedTime = $editedTime;
	}

	/**
	 * @return string
	 */
	public function getCreatedByAccount(): string
	{
		return $this->createdByAccount;
	}

	/**
	 * @param string $createdByAccount
	 */
	public function setCreatedByAccount(string $createdByAccount)
	{
		$this->createdByAccount = $createdByAccount;
	}

	/**
	 * @return string
	 */
	public function getEditedByAccount(): string
	{
		return $this->editedByAccount;
	}

	/**
	 * @param string $editedByAccount
	 */
	public function setEditedByAccount(string $editedByAccount)
	{
		$this->editedByAccount = $editedByAccount;
	}

	/**
	 * @return bool
	 */
	public function isLocked(): bool
	{
		return $this->locked;
	}

	/**
	 * @param bool $locked
	 */
	public function setLocked(bool $locked)
	{
		$this->locked = $locked;
	}

	/**
	 * @return string
	 */
	public function getContents(): string
	{
		return $this->contents;
	}

	/**
	 * @param string $contents
	 */
	public function setContents(string $contents)
	{
		$this->contents = $contents;
	}

	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return [
			'name' => $this->getName(),
			'createdTime' => $this->getCreatedTime(),
			'createdByAccount' => $this->getCreatedByAccount(),
			'editedTime' => $this->getEditedTime(),
			'editedByAccount' => $this->getEditedByAccount(),
			'locked' => $this->isLocked(),
			'contents' => $this->getContents()
		];
	}
}