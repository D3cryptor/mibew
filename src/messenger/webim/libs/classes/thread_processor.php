<?php
/*
 * Copyright 2005-2013 the original author or authors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Incapsulates thread api and thread processing functions.
 *
 * Register events (see RequestProcessor::registerEvents() for details):
 *  - threadRequestReceived
 *  - threadReceiveRequestError
 *  - threadCallError
 *  - threadFunctionCall
 *
 * WARNING:
 *  threadResponseReceived registered but never called because of asynchronous nature of Core-to-Window interaction
 *
 * Implements Singleton pattern
 */
class ThreadProcessor extends ClientSideProcessor {

	/**
	 * An instance of the ThreadProcessor class
	 * @var ThreadProcessor
	 */
	protected static $instance = null;

	/**
	 * Return an instance of the ThreadProcessor class.
	 * @return ThreadProcessor
	 */
	public static function getInstance() {
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Loads thread by id and token and checks if thread loaded
	 *
	 * @param int $thread_id Id of the thread
	 * @param int $last_token Last token of the thread
	 * @return Thread
	 * @throws ThreadProcessorException
	 */
	public static function getThread($thread_id, $last_token) {
		// Load thread
		$thread = Thread::load($thread_id, $last_token);
		// Check thread
		if (! $thread) {
			throw new ThreadProcessorException(
				'Wrong thread',
				ThreadProcessorException::ERROR_WRONG_THREAD
			);
		}
		// Return thread
		return $thread;
	}

	/**
	 * Check if arguments exists in $args array
	 *
	 * @param array $args Arguments array
	 * @param array $vars Array of arguments names that must be checked
	 * @throws ThreadProcessorException
	 */
	public static function checkParams($args, $vars) {
		if (empty($vars)) {
			return;
		}
		// Check variables exists
		foreach ($vars as $var) {
			if (! array_key_exists($var, $args)) {
				throw new ThreadProcessorException(
					"There is no '{$var}' variable in arguments list",
					ThreadProcessorException::ERROR_WRONG_ARGUMENTS
				);
			}
		}
	}

	/**
	 * Class constructor
	 *
	 * Do not use directly __construct method! Use ThreadProcessor::getInstance() instead!
	 */
	public function __construct() {
		parent::__construct(array(
			'signature' => '',
			'trusted_signatures' => array(''),
			'event_prefix' => 'thread'
		));
	}

	/**
	 * Creates and returns an instance of the MibewAPI class.
	 *
	 * @return MibewAPI
	 */
	protected function getMibewAPIInstance() {
		return MibewAPI::getAPI('MibewAPIChatInteraction');
	}

	/**
	 * Sends asynchronous request
	 *
	 * @param array $request The 'request' array. See Mibew API for details
	 * @return boolean true on success or false on failure
	 */
	protected function sendAsyncRequest($request) {
		// Define empty thread id and thread token
		$thread_id = null;
		$token = null;
		foreach ($request['functions'] as $function) {
			// Save thread id and thread token from first function in package
			if (is_null($thread_id)) {
				$thread_id = $function['arguments']['threadId'];
				$token = $function['arguments']['token'];
				continue;
			}
			// Check thread id and thread token for the remaining functions
			if ($thread_id != $function['arguments']['threadId'] || $token != $function['arguments']['token']) {
				throw new ThreadProcessorException(
					'Various thread id or thread token in different functions in one package!',
					ThreadProcessorException::VARIOUS_THREAD_ID
				);
			}
		}
		// Store request in buffer
		$this->addRequestToBuffer('thread_'.$thread_id, $request);
		return true;
	}

	/**
	 * Send new messages to window
	 *
	 * Call updateMessages at window side
	 *
	 * @global string $webim_encoding
	 * @param Thread $thread Messages sends to this thread
	 * @param boolead $is_user TRUE if messages sends to user and FALSE otherwise
	 * @param int $last_message_id Id of the last sent message
	 */
	protected function sendMessages(Thread $thread, $is_user, $last_message_id) {
		$messages = $thread->getMessages($is_user, $last_message_id);
		if (! empty($messages)) {
			foreach($messages as $key => $msg) {
				// Check if message is avatar
				if ($msg['kind'] == Thread::KIND_AVATAR) {
					// Update avatar
					$this->responses[] = array(
						'token' => md5(time() . rand()),
						'functions' => array(
							array(
								'function' => 'setupAvatar',
								'arguments' => array(
									'threadId' => $thread->id,
									'token' => $thread->lastToken,
									'return' => array(),
									'references' => array(),
									'imageLink' => $msg['message']
								)
							)
						)
					);
					unset($messages[$key]);
					continue;
				}
			}
			// Send messages
			$this->responses[] = array(
				'token' => md5(time() . rand()),
				'functions' => array(
					array(
						'function' => 'updateMessages',
						'arguments' => array(
							'threadId' => $thread->id,
							'token' => $thread->lastToken,
							'return' => array(),
							'references' => array(),
							'messages' => $messages,
							'lastId' => $last_message_id
						)
					)
				)
			);
		}
	}

	/**
	 * Update chat window state. API function
	 *
	 * Call periodically by chat window
	 * @param array $args Associative array of arguments. It must contains following keys:
	 *  - 'threadId': Id of the thread related to chat window
	 *  - 'token': last thread token
	 *  - 'user': TRUE if window used by user and FALSE otherwise
	 *  - 'typed': indicates if user(or agent) typed
	 *  - 'lastId': id of the last sent to message
	 * @return array Array of results. It contains following keys:
	 *  - 'typing': indicates if another side of the conversation is typing message
	 *  - 'canPost': indicates if agent(user can post message all the time) can post the message
	 */
	protected function apiUpdate($args) {
		// Load thread
		$thread = self::getThread($args['threadId'], $args['token']);

		// Check variables
		self::checkParams($args, array('user', 'typed', 'lastId'));

		if (! $args['user']) {
			$operator = check_login();
			$thread->checkForReassign($operator);
		}

		$thread->ping($args['user'], $args['typed']);

		// Update messages
		$this->sendMessages($thread, $args['user'], $args['lastId']);

		// Load stored requests
		$stored_requests = $this->getRequestsFromBuffer('thread_'.$thread->id);
		if ($stored_requests !== false) {
			$this->responses = array_merge($this->responses, $stored_requests);
		}

		// Get status values
		if ($args['user']) {
			$is_typing = abs($thread->lastPingAgent - time()) < Thread::CONNECTION_TIMEOUT && $thread->agentTyping;
		} else {
			$is_typing = abs($thread->lastPingUser - time()) < Thread::CONNECTION_TIMEOUT && $thread->userTyping;
		}
		$can_post = $args['user'] || $operator['operatorid'] == $thread->agentId;

		return array(
			'typing' => $is_typing,
			'canPost' => $can_post
		);
	}

	/**
	 * Post message to thread. API function
	 *
	 * @param array $args Associative array of arguments. It must contains following keys:
	 *  - 'threadId': Id of the thread related to chat window
	 *  - 'token': last thread token
	 *  - 'user': TRUE if window used by user and FALSE otherwise
	 *  - 'message': posted message
	 * @throws ThreadProcessorException
	 */
	protected function apiPost($args) {
		// Load thread
		$thread = self::getThread($args['threadId'], $args['token']);

		// Check variables
		self::checkParams($args, array('user', 'message'));

		// Get operator's array
		if (! $args['user']) {
			$operator = check_login();
		}

		// Check message can be sent
		if(! $args['user'] && $operator['operatorid'] != $thread->agentId) {
			throw new ThreadProcessorException("Cannot send", ThreadProcessorException::ERROR_CANNOT_SEND);
		}

		// Set fields
		$kind = $args['user'] ? Thread::KIND_USER : Thread::KIND_AGENT;
		$from = $args['user'] ? $thread->userName : $thread->agentName;
		$opid = $args['user'] ? null : $operator['operatorid'];

		// Post message
		$posted_id = $thread->postMessage($kind, $args['message'], $from, $opid);

		// Update shownMessageId
		if($args['user'] && $thread->shownMessageId == 0) {
			$thread->shownMessageId = $posted_id;
			$thread->save();
		}
	}

	/**
	 * Rename user in the chat. API function
	 *
	 * @param array $args Associative array of arguments. It must contains following keys:
	 *  - 'threadId': Id of the thread related to chat window
	 *  - 'token': last thread token
	 *  - 'name': new user name
	 * @throws ThreadProcessorException
	 */
	protected function apiRename($args) {
		global $namecookie, $webim_encoding;

		// Check rename possibility
		if( Settings::get('usercanchangename') != "1" ) {
			throw new ThreadProcessorException(
				'server: forbidden to change name',
				ThreadProcessorException::ERROR_FORBIDDEN_RENAME
			);
		}

		// Load thread
		$thread = self::getThread($args['threadId'], $args['token']);

		// Check if new name exists
		self::checkParams($args, array('name'));

		//Rename user
		$thread->renameUser($args['name']);
		// Update user name in cookies
		$data = strtr(base64_encode(myiconv($webim_encoding,"utf-8",$args['name'])), '+/=', '-_,');
		setcookie($namecookie, $data, time()+60*60*24*365);
	}

	/**
	 * Close chat thread. API function
	 *
	 * @param array $args Associative array of arguments. It must contains following keys:
	 *  - 'threadId': Id of the thread related to chat window
	 *  - 'token': last thread token
	 *  - 'user': TRUE if window used by user and FALSE otherwise
	 * @return array Array of results. It contains following keys:
	 *  - 'closed': indicates if thread can be closed
	 */
	protected function apiClose($args) {
		// Load thread and check thread's last token
		$thread = self::getThread($args['threadId'], $args['token']);

		// Check if new user variable exists
		self::checkParams($args, array('user'));

		// Load operator
		if (! $args['user']) {
			$operator = check_login();
		}

		// Close thread
		if( $args['user'] || $thread->agentId == $operator['operatorid']) {
			$thread->close($args['user']);
		}

		return array(
			'closed' => true
		);
	}
}

class ThreadProcessorException extends RequestProcessorException {
	/**
	 * Wrong arguments set for an API function
	 */
	const ERROR_WRONG_ARGUMENTS = 1;
	/**
	 * Thread cannot be loaded
	 */
	const ERROR_WRONG_THREAD = 2;
	/**
	 * Message cannot be send
	 */
	const ERROR_CANNOT_SEND = 3;
	/**
	 * User rename forbidden by system configurations
	 */
	const ERROR_FORBIDDEN_RENAME = 4;
	/**
	 * Various thread ids or thread tokens in different functions in one package
	 */
	const VARIOUS_THREAD_ID = 5;
}

?>