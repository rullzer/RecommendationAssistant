<?php
/**
 * @copyright Copyright (c) 2017, Dogan Ucar (dogan@dogan-ucar.de)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\RecommendationAssistant\Hook;

use OCA\RecommendationAssistant\Db\ChangedFilesManager;
use OCA\RecommendationAssistant\Db\ProcessedFilesManager;
use OCA\RecommendationAssistant\Log\Logger;
use OCA\RecommendationAssistant\Util\NodeUtil;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * FileHook class is registered as a hook in OCA\RecommendationAssistant\AppInfo\Application
 * and is called when:
 *
 * <ul>a file is tagged as favorite</ul>
 * <ul>a file is changed</ul>
 *
 * Shortdescription: the run method of this class is registered as a hook in
 * OCA\RecommendationAssistant\AppInfo\Application.php.
 * The method is executed when a file is changed or tagged as favorite.
 *
 * @package OCA\RecommendationAssistant\Service
 * @since 1.0.0
 */
class FileHook {
	/**
	 * @var IUserSession $userSession the current user session
	 */
	private $userSession = null;

	/**
	 * @var IRequest $request the request
	 */
	private $request = null;

	/**
	 * @var IRootFolder $rootFolder the users root folder
	 */
	private $rootFolder = null;

	/**
	 * @var ProcessedFilesManager the database manager class for data queries
	 */
	private $processedFileManager = null;

	/**
	 * @var ChangedFilesManager $changedFilesManager the database manager
	 * class for data queries
	 */
	private $changedFilesManager = null;

	/**
	 * Class constructor gets multiple instances injected
	 *
	 * @param IUserSession $userSession the current user session
	 * @param IRequest $request the current request
	 * @param IRootFolder $rootFolder the users root folder
	 * @param ProcessedFilesManager $processedFilesManager database manager class
	 * to query already processed files
	 * @param ChangedFilesManager $changedFilesManager database manager class
	 * to query changed files
	 *
	 * @package OCA\RecommendationAssistant\AppInfo
	 * @since 1.0.0
	 */
	public function __construct(
		IUserSession $userSession,
		IRequest $request,
		IRootFolder $rootFolder,
		ProcessedFilesManager $processedFilesManager,
		ChangedFilesManager $changedFilesManager) {

		$this->userSession = $userSession;
		$this->request = $request;
		$this->rootFolder = $rootFolder;
		$this->processedFileManager = $processedFilesManager;
		$this->changedFilesManager = $changedFilesManager;
	}

	/**
	 * runs the file hook for edited files. The method inserts the last
	 * changed timestamp for a given file path.
	 *
	 * @param array $parameters
	 * @return bool
	 *
	 * @since 1.0.0
	 */
	public function run($parameters): bool {
		Logger::debug("FileHook start");
		$path = $parameters["path"];
		/** @var IUser $user */
		$user = $this->userSession->getUser();

		/*
		 * .part files are parts of a file. When transmitting,
		 * large files are divided in multiple files.
		 */
		if (substr($path, -5) === '.part') {
			Logger::info("ignoring file because it is a .part file");
			return false;
		}

		/*
		 * No session available. User is not logged in. Therefore, returning
		 * false.
		 */
		if ($user === null) {
			Logger::info("ignoring file because user is not logged in");
			return false;
		}

		/*
		 * User has to make changes via the web UI. Otherwise, changes are
		 * not recognized as a "change".
		 */
		if ($this->request->isUserAgent([
			IRequest::USER_AGENT_CLIENT_DESKTOP,
			IRequest::USER_AGENT_CLIENT_ANDROID,
			IRequest::USER_AGENT_CLIENT_IOS])) {
			Logger::info("ignoring file because request is not from web UI");
			return false;
		}

		/*
 		 * no path available, no file provided
 		 */
		if ($path === '/' || $path === '' || $path === null) {
			Logger::info("ignoring file because no path available, no file provided");
			return false;
		}
		/** @var Node $node */
		$node = $this->getNode($path);

		if (null === $node) {
			Logger::info("node returned null. Cannot process file");
			return false;
		}

		if ($node instanceof File) {
			/*
			 * inserting the file to the changed files
			 */
			$this->changedFilesManager->deleteBeforeInsert($node, $user->getUID(), "edit");

			/*
			 * deleting the file in order to re-read its content after a change
			 */
			$this->processedFileManager->deleteFile($node, "userprofile");

			//TODO delete from recommendations in order to decide about a re-recommendation?
			Logger::debug("FileHook end");
			return true;
		}

		Logger::debug("FileHook end");
		return false;
	}

	/**
	 * returns the Node instance that correspondents to $path
	 *
	 * @param string $path the path
	 * @return null|Node the node that is requested or null, if an error occures
	 *
	 * @since 1.0.0
	 */
	private function getNode($path) {
		$node = null;
		try {
			$currentUserId = $this->userSession->getUser()->getUID();
			$userFolder = $this->rootFolder->getUserFolder($currentUserId);
			$node = $userFolder->get($path);
		} catch (NotFoundException $e) {
			Logger::error($e->getMessage());
		}
		return $node;
	}

	/**
	 * runs the file hook for tagged files. The method inserts the last
	 * changed timestamp for a given file id.
	 *
	 * @param string $userId
	 * @param string $fileId
	 * @param string $caller
	 *
	 * @since 1.0.0
	 */
	public function runFavorite(string $userId, string $fileId, string $caller) {
		$node = NodeUtil::getFile($fileId, $userId);
		if (null === $node) {
			Logger::info("$fileId for $userId not available. Skipping");
			return;
		}
		if ("addFavorite" === $caller) {
			$this->changedFilesManager->deleteBeforeInsert($node, $userId, "favorite");
		} else if ("removeFavorite" === $caller) {
			$this->changedFilesManager->deleteFile($node, $userId, "favorite");
		}
	}
}