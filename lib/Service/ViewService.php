<?php

/** @noinspection DuplicatedCode */

namespace OCA\Tables\Service;

use DateTime;
use Exception;

use OCA\Tables\Db\Table;
use OCA\Tables\Db\View;
use OCA\Tables\Db\ViewMapper;


use OCA\Tables\Errors\InternalError;
use OCA\Tables\Errors\NotFoundError;
use OCA\Tables\Errors\PermissionError;
use OCA\Tables\Helper\UserHelper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class ViewService extends SuperService {
	private ViewMapper $mapper;

	private ShareService $shareService;

	private RowService $rowService;

	protected UserHelper $userHelper;

	protected IL10N $l;

	public function __construct(
		PermissionsService $permissionsService,
		LoggerInterface $logger,
		?string $userId,
		ViewMapper $mapper,
		ShareService $shareService,
		RowService $rowService,
		UserHelper $userHelper,
		IL10N $l
	) {
		parent::__construct($logger, $userId, $permissionsService);
		$this->l = $l;
		$this->mapper = $mapper;
		$this->shareService = $shareService;
		$this->rowService = $rowService;
		$this->userHelper = $userHelper;
	}


	/**
	 * @param Table $table
	 * @param string|null $userId
	 * @return array
	 * @throws InternalError
	 * @throws PermissionError
	 */
	public function findAll(Table $table, ?string $userId = null): array {
		$userId = $this->permissionsService->preCheckUserId($userId); // $userId can be set or ''

		try {
			// security
			if (!$this->permissionsService->canManageTable($table, $userId)) {
				throw new PermissionError('PermissionError: can not read views for tableId '.$table->getId());
			}

			$allViews = $this->mapper->findAll($table->getId());
			foreach ($allViews as $view) {
				$this->enhanceView($view, $userId);
			}
			return $allViews;
		} catch (\OCP\DB\Exception|InternalError $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			throw new InternalError($e->getMessage());
		} catch (PermissionError $e) {
			$this->logger->debug('permission error during looking for views', ['exception' => $e]);
			throw new PermissionError($e->getMessage());
		}
	}

	/**
	 * @param int $id
	 * @param bool $skipEnhancement
	 * @param string|null $userId (null -> take from session, '' -> no user in context)
	 * @return View
	 * @throws DoesNotExistException
	 * @throws InternalError
	 * @throws MultipleObjectsReturnedException
	 * @throws PermissionError
	 */
	public function find(int $id, bool $skipEnhancement = false, ?string $userId = null): View {
		/** @var string $userId */
		$userId = $this->permissionsService->preCheckUserId($userId); // $userId can be set or ''

		try {
			$view = $this->mapper->find($id);
		} catch (\OCP\DB\Exception $e) {
			$this->logger->error($e->getMessage());
			throw new InternalError($e->getMessage());
		}

		// security

		if (!$this->permissionsService->canAccessView($view, $userId)) {
			throw new PermissionError('PermissionError: can not read view with id '.$id);
		}
		if(!$skipEnhancement) {
			$this->enhanceView($view, $userId);
		}

		return $view;
	}

	/**
	 * @throws InternalError
	 */
	public function findSharedViewsWithMe(?string $userId = null): array {
		/** @var string $userId */
		$userId = $this->permissionsService->preCheckUserId($userId); // $userId can be set or ''
		if ($userId === '') {
			return [];
		}
		$sharedViews = $this->shareService->findViewsSharedWithMe($userId);
		foreach ($sharedViews as $view) {
			$this->enhanceView($view, $userId);
		}
		return $sharedViews;
	}


	/**
	 * @param string $title
	 * @param string|null $emoji
	 * @param Table $table
	 * @param string|null $userId
	 * @return View
	 * @throws InternalError
	 * @throws PermissionError
	 */
	public function create(string $title, ?string $emoji, Table $table, ?string $userId = null): View {
		/** @var string $userId */
		$userId = $this->permissionsService->preCheckUserId($userId, false); // $userId is set

		// security
		if (!$this->permissionsService->canManageTable($table, $userId)) {
			throw new PermissionError('PermissionError: can not create view');
		}

		$time = new DateTime();
		$item = new View();
		$item->setTitle($title);
		if($emoji) {
			$item->setEmoji($emoji);
		}
		$item->setDescription('');
		$item->setTableId($table->getId());
		$item->setCreatedBy($userId);
		$item->setLastEditBy($userId);
		$item->setCreatedAt($time->format('Y-m-d H:i:s'));
		$item->setLastEditAt($time->format('Y-m-d H:i:s'));
		try {
			$newItem = $this->mapper->insert($item);
		} catch (\OCP\DB\Exception $e) {
			$this->logger->error($e->getMessage());
			throw new InternalError($e->getMessage());
		}

		return $newItem;
	}


	/**
	 * @param int $id
	 * @param string $key
	 * @param string|null $value
	 * @param string|null $userId
	 * @return View
	 * @throws InternalError
	 */
	public function updateSingle(int $id, string $key, ?string $value, ?string $userId = null): View {
		return $this->update($id, [$key => $value], $userId);
	}

	/**
	 * @param int $id
	 * @param array $data
	 * @param string|null $userId
	 * @param bool $skipTableEnhancement
	 * @return View
	 * @throws InternalError
	 */
	public function update(int $id, array $data, ?string $userId = null, bool $skipTableEnhancement = false): View {
		$userId = $this->permissionsService->preCheckUserId($userId);

		try {
			$view = $this->mapper->find($id);

			// security
			if (!$this->permissionsService->canManageView($view, $userId)) {
				throw new PermissionError('PermissionError: can not update view with id '.$id);
			}

			$updatableParameter = array('title', 'emoji', 'description', 'columns', 'sort', 'filter');

			foreach ($data as $key => $value) {
				if (!in_array($key, $updatableParameter)) {
					throw new InternalError('View parameter '.$key.' can not be updated.');
				}
				$setterMethod = 'set'.ucfirst($key);
				$view->$setterMethod($value);
			}
			$time = new DateTime();
			$view->setLastEditBy($userId);
			$view->setLastEditAt($time->format('Y-m-d H:i:s'));
			$view = $this->mapper->update($view);
			if(!$skipTableEnhancement) {
				$this->enhanceView($view, $userId);
			}
			return $view;
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			throw new InternalError($e->getMessage());
		}
	}

	/**
	 * @param int $id
	 * @param string|null $userId
	 * @return View
	 * @throws InternalError
	 */
	public function delete(int $id, ?string $userId = null): View {
		/** @var string $userId */
		$userId = $this->permissionsService->preCheckUserId($userId); // $userId is set or ''

		try {
			$view = $this->mapper->find($id);

			// security
			if (!$this->permissionsService->canManageView($view, $userId)) {
				throw new PermissionError('PermissionError: can not delete view with id '.$id);
			}
			$this->shareService->deleteAllForView($view);

			return $this->mapper->delete($view);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage());
			throw new InternalError($e->getMessage());
		}
	}


	/**
	 * @param View $view
	 * @param string|null $userId
	 * @return View
	 * @throws InternalError
	 */
	public function deleteByObject(View $view, ?string $userId = null): View {
		/** @var string $userId */
		$userId = $this->permissionsService->preCheckUserId($userId); // $userId is set or ''

		try {
			// security
			if (!$this->permissionsService->canManageView($view, $userId)) {
				throw new PermissionError('PermissionError: can not delete view with id '.$view->getId());
			}
			// delete all shares for that table
			$this->shareService->deleteAllForView($view);

			$this->mapper->delete($view);
			return $view;
		} catch (Exception $e) {
			$this->logger->error($e->getMessage());
			throw new InternalError($e->getMessage());
		}
	}

	/**
	 * add some basic values related to this view in context
	 *
	 * $userId can be set or ''
	 */
	private function enhanceView(View $view, string $userId): void {
		// add owner display name for UI
		$view->setOwnerDisplayName($this->userHelper->getUserDisplayName($view->getOwnership()));

		// set if this is a shared table with you (somebody else shared it with you)
		// (senseless if we have no user in context)
		if ($userId !== '') {
			if ($userId !== $view->getOwnership()) {
				try {
					$permissions = $this->shareService->getSharedPermissionsIfSharedWithMe($view->getId(), 'view', $userId);
					$view->setIsShared(true);
					$canManageTable = false;
					try {
						$manageTableShare = $this->shareService->getSharedPermissionsIfSharedWithMe($view->getTableId(), 'table', $userId);
						$canManageTable = $manageTableShare['manage'] ?? false;
					} catch (NotFoundError $e) {
					} catch (\Exception $e) {
						throw new InternalError($e->getMessage());
					}
					$view->setOnSharePermissions([
						'read' => $permissions['read'] ?? false,
						'create' => $permissions['create'] ?? false,
						'update' => $permissions['update'] ?? false,
						'delete' => $permissions['delete'] ?? false,
						'manage' => $permissions['manage'] ?? false,
						'manageTable' => $canManageTable
					]);
				} catch (NotFoundError $e) {
				} catch (\Exception $e) {
					$this->logger->warning('Exception occurred while setting shared permissions: '.$e->getMessage().' No permissions granted.');
					$view->setOnSharePermissions([
						'read' => false,
						'create' => false,
						'update' => false,
						'delete' => false,
						'manage' => false,
						'manageTable' => false
					]);
				}
			} else {
				// set hasShares if this table is shared by you (you share it with somebody else)
				// (senseless if we have no user in context)
				try {
					$allShares = $this->shareService->findAll('view', $view->getId());
					$view->setHasShares(count($allShares) !== 0);
				} catch (InternalError $e) {
				}
			}

		}

		if (!$this->permissionsService->canReadRowsByElement($view, 'view', $userId)) {
			return;
		}
		// add the rows count
		try {
			$view->setRowsCount($this->rowService->getViewRowsCount($view, $userId));
		} catch (InternalError|PermissionError $e) {
		}

		if($view->getIsShared()) {
			// Remove detailed view filtering and sorting information if necessary
			if(!$view->getOnSharePermissions()['manageTable']) {
				$view->setFilterArray(
					array_map(function ($filterGroup) {
						// Instead of filter just indicate that there is a filter, but hide details
						return array_map(null, $filterGroup);
					},
						$view->getFilterArray()));
				$view->setSortArray(
					array_map(function ($sortRule) use ($view) {
						if(in_array($sortRule["columnId"], $view->getColumnsArray())) {
							return $sortRule;
						}
						// Instead of sort rule just indicate that there is a rule, but hide details
						return null;
					},
						$view->getSortArray()));
			}
		}
	}

	/**
	 * @param Table $table
	 * @param null|string $userId
	 * @return void
	 * @throws InternalError
	 * @throws PermissionError
	 */
	public function deleteAllByTable(Table $table, ?string $userId = null): void {
		// security
		if (!$this->permissionsService->canManageTable($table, $userId)) {
			throw new PermissionError('delete all rows for table id = '.$table->getId().' is not allowed.');
		}
		$views = $this->findAll($table, $userId);
		foreach ($views as $view) {
			$this->deleteByObject($view, $userId);
		}
	}

	/**
	 * @param int $columnId
	 * @param Table $table
	 * @return void
	 * @throws InternalError
	 */
	public function deleteColumnDataFromViews(int $columnId, Table $table) {
		try {
			$views = $this->mapper->findAll($table->getId());
		} catch (\OCP\DB\Exception $e) {
			throw new InternalError($e->getMessage());
		}
		foreach ($views as $view) {
			$filteredSortingRules = array_filter($view->getSortArray(), function (array $sort) use ($columnId) {
				return $sort['columnId'] !== $columnId;
			});
			$filteredSortingRules = array_values($filteredSortingRules);
			$filteredFilters = array_filter(array_map(function (array $filterGroup) use ($columnId) {
				return array_filter($filterGroup, function (array $filter) use ($columnId) {
					return $filter['columnId'] !== $columnId;
				});
			}, $view->getFilterArray()), fn ($filterGroup) => !empty($filterGroup));
			$data = [
				'columns' => json_encode(array_values(array_diff($view->getColumnsArray(), [$columnId]))),
				'sort' => json_encode($filteredSortingRules),
				'filter' => json_encode($filteredFilters),
			];

			$this->update($view->getId(), $data);
		}
	}

	/**
	 * @param string $term
	 * @param int $limit
	 * @param int $offset
	 * @param string|null $userId
	 * @return array
	 */
	public function search(string $term, int $limit = 100, int $offset = 0, ?string $userId = null): array {
		try {
			/** @var string $userId */
			$userId = $this->permissionsService->preCheckUserId($userId);
			$views = $this->mapper->search($term, $userId, $limit, $offset);
			foreach ($views as $view) {
				$this->enhanceView($view, $userId);
			}
			return $views;
		} catch (InternalError | \OCP\DB\Exception $e) {
			return [];
		}
	}
}
