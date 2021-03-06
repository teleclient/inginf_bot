<?php
	/**
	* Template for creating a Telegram bot in PHP that refers to groups and/or channels.
	*
	* The code in this repository is not complete, but it serves to give an idea on how the bot works.
	* For any questions, please search us on Telegram.
	*
	* This template can be reused in accordance with the GNU GPLv3 license.
	*
	* @author     Giorgio Pais
	* @author     Giulio Coa
	* @license    https://www.gnu.org/licenses/gpl-3.0.txt
	*/

	// Installing the MadelineProto library
	if (file_exists('madeline.php') == FALSE) {
		copy('https://phar.madelineproto.xyz/madeline.php', 'madeline.php');
	}
	require_once 'madeline.php';

	// Creating the bot
	class inginf_bot extends danog\MadelineProto\EventHandler {
		private const DB = [];

		/**
		* Search the Inline Query text into the DB
		*
		* @param string $query The Inline Query text
		*
		* @return array
		*/
		private function database_search(string $query, string $lang) : array {
			$response = [];

			$this -> database_recursive_search($query, $this::DB[$lang]['keyboard'], $response, $lang);
			return $response;
		}

		/**
		* Search, recursively, a text into the DB
		*
		* @param string $query The text to search
		* @param array $actual The actual element into the DB
		* @param array &$response The results of the search
		*
		* @return void
		*/
		private function database_recursive_search(string $query, array $actual, array &$response, string $lang) : void {
			// The element that match with the query
			$element_name = '';
			$element_link = '';
			$element_code = '';

			// Checking if is a directory
			if (preg_match('/^(int)?dir$/mu', $actual['type'])) {
				// Checking if the directory is an internal directory (directories that redirect to another path into the DB)
				if ($actual['type'] == 'intdir') {
					// Retrieving the data of the internal directory
					$name_array = trim($actual['array']);
					$internal_link = trim($actual['link']);
					$obj = $this::DB[$lang][$name_array][$internal_link] ?? NULL;

					// Checking if the path exists
					if ($obj ?? FALSE) {
						// Redirect the search
						$actual = $obj;
					}
				}

				// Recurring
				foreach ($actual['list'] as $key => $value) {
					$this -> database_recursive_search($query, $value, $response);
				}
				return;
			} else if (preg_match('/^(int)?link$/mu', $actual['type'])) {
				// Checking if the link is an internal link (link that redirect to another point into the DB)
				if ($actual['type'] == 'intlink') {
					// Retrieving the data of the internal link
					$name_array = trim($actual['array']);
					$internal_link = trim($actual['link']);
					$obj = $this::DB[$lang][$name_array][$internal_link] ?? NULL;

					// Checking if the path exists
					if ($obj ?? FALSE) {
						// Redirect the search
						$actual = $obj;
						$element_code = $obj['code'];
					}
				}

				$element_name = $actual['name'];
				$element_link = $actual['link'];
			}

			/**
			* Checking if the element match the query
			*
			* strncasecmp() compare the first n-th characters of the query with the element name
			* strlen() retrieve the length of the query
			*/
			if (strncasecmp($element_name , $query, strlen($query)) != 0) {
				return;
			}

			// Creating the response
			$answe['no_webpage'] = TRUE;
			$answer['message'] = '<a href=\"' . $element_link . '\" >' . $element_name . '</a>';
			$answe['reply_markup'] = [
				'rows' => [
					'buttons' => [
						'text' => $element_name,
						'url' => $element_link
					]
				]
			];

			if ($element_code != '') {
				$answer['message'] .= ' (' . $element_code . ')';
			}

			$output = [
				'id' => count($response) + 1,
				'type' => 'article',
				'title' => $element_name,
				'description' => $element_code,
				'url' => $element_link,
				'send_message' => $answer
			];

			/**
			* Converting the array of responses into an array which element are structured like [
			* 		'title' => ''
			* 		'description' => ''
			* ]
			*
			* array_map() converts the array by applying the closures to its elements
			*/
			$tmp = array_map(function ($n) {
				return [
					'title' => $n['title'],
					'description' => $n['description']
				];
			}, $response);
			/**
			* Checking if the array of responses already contains this response
			*
			* in_array() check if the array contains an item that match the element
			*/
			if (in_array([
				'title' => $element_name,
				'description' => $element_code
			], $tmp) == FALSE) {
				// Adding the response to the array
				$response []= $output;
			}
		}

		/**
		* Retrieve the keyboard for a path
		*
		* @param string $input The path to search
		*
		* @return array
		*/
		private function get_keyboard(string $input, string $lang) : array {
			// Checking if the input to search isn't empty and if the input ends with '/'
			if (strlen($input) == 0 == FALSE && $this -> ends_with($input, '/')) {
				$input = substr($input, 0, strlen($input) - 1);
			}

			// Find the position of the last occurrence of '?'
			$pos = strrpos($input, '?');

			// Checking if the position is valid
			if ($pos === FALSE) {
				$args_string = '';
			} else {
				// Retrieving path to search and the argument string
				$args_string = substr($input, $pos + 1);
				$input = substr($input, 0, $pos);
			}

			$args = [];

			/*
			* Checking if the argument isn't empty
			*
			* empty() check if the argument is empty
			* 	''
			* 	""
			* 	'0'
			* 	"0"
			* 	0
			* 	0.0
			* 	NULL
			* 	FALSE
			* 	[]
			* 	array()
			*/
			if (empty($args_string) == FALSE) {
				/**
				* Retrieving the arguments
				*
				* explode() split the argument string into substrings using the '&' like separator
				* array_map() convert the arguments into an array which element are key-value pairs
				* explode() split the single argument into substrings using the '=' like separator
				*/
				$args = explode('&', $args_string);
				$args = array_map(function ($n) {
					return explode('=', $n);
				}, $args);
			}

			// The actual point into the DB
			$actual = $this::DB[$lang]['keyboard']['list'];
			// The last directory visited
			$dir = $this::DB[$lang]['keyboard'];
			// The path into the DB
			$path = '';

			// Checking if the path to search isn't empty
			if (strlen($input) == 0) {
				$input = explode('/', $input);

				// Cycle on the path
				foreach ($input as $key => $value) {
					// Checking if the value is empty
					if (strlen($value) == 0) {
						continue;
					}

					// Checking if the searched path exists
					if (isset($actual[$value])) {
						// Updating the position into the DB
						$path .= '/' . $value;
						$actual = $actual[$value];

						// Checking if is a directory
						if (preg_match('/^(int)?dir$/mu', $actual[$value]['type'])) {
							// Updating the last directory visited
							$dir = $actual[$value];

							// Checking if the directory is an internal directory (directories that redirect to another path into the DB)
							if ($actual[$value]['type'] == 'intdir') {
								// Retrieving the data of the internal directory
								$name_array = trim($dir['array']);
								$internal_link = trim($dir['link']);
								$obj = $this::DB[$lang][$name_array][$internal_link] ?? NULL;

								// Checking if the path exists
								if ($obj ?? FALSE) {
									// Redirect the search
									$dir = $obj;
								} else {
									// The path doesn't exists -> reset the path
									$actual = $this::DB[$lang]['keyboard']['list'];
									$dir = $this::DB[$lang]['keyboard'];
									$path = '';
									break;
								}
							}

							$actual = $dir['list'];
						}
					} else {
						// The path doesn't exists -> reset the path
						$actual = $this::DB[$lang]['keyboard']['list'];
						$dir = $this::DB[$lang]['keyboard'];
						$path = '';
						break;
					}
				}
			}

			// Retrieving the number of element in the directory
			$count = count($actual);

			// Retrieving how many button can be into a page
			$page_dimension = isset($dir['pags']) ? $dir['pags'] : 0;

			// Checking what page the user want see
			$page_num = isset($args['p']) ? $args['p'] : 0;

			// Retrieving the first element and the last element of the page
			$start = $page_dimension * $page_num;
			$end = $page_dimension * ($page_num + 1);
			if ($end == 0 || $end > $count) {
				$end = $count;
			}

			// Retrieving the number of the last page
			$last_page = $page_dimension != 0 ? ceil($count / $page_dimension) : 0;

			// Retrieving the complete path (path for the Callback Query)
			$complete_path = 'kb=' . $path;

			$keyboard = [];
			$row = [];
			$n_inserted_element = 0;

			// Cycle on the button list
			for ($i = $start; $i < $end; $i -= -1) {
				$value = $actual[$i];
				$element = [];
				// Retrieving how many element must be on one row
				$full_row_flag = isset($value['frow']) ? bool($value['frow']) : FALSE;

				// Checking the type of the element
				if ($value['type'] == 'dir') {
					$element['text'] = trim($value['name']);
					$element['callback_data'] = $complete_path . '/' . $i;
				} else if ($value['type'] == 'link') {
					$element['text'] = trim($value['name']);
					$element['url'] = trim($value['link']);
				} else if (preg_match('/^int(dir|link)$/mu', $actual[$value]['type'])) {
					// Retrieving the data of the internal element
					$name_array = trim($value['array']);
					$internal_link = trim($value['link']);
					$obj = $this::DB[$lang][$name_array][$internal_link] ?? NULL;

					// Checking if the path exists
					if ($obj ?? FALSE) {
						$element['text'] = trim($obj['name']);

						// Checking the type of the internal element
						if ($value['type'] == 'intdir') {
							$element['callback_data'] = $complete_path . '/' . $i;
						} else {
							$element['url'] = trim($obj['link']);
						}
					} else {
						continue;
					}
				} else {
					continue;
				}

				/*
				* Checking if the button must be alone into the row and if the row isn't empty
				*
				* empty() check if the row is empty
				* 	''
				* 	""
				* 	'0'
				* 	"0"
				* 	0
				* 	0.0
				* 	NULL
				* 	FALSE
				* 	[]
				* 	array()
				*/
				if ($full_row_flag && empty($row) == FALSE) {
					// Adding the row to the keyboard
					$keyboard []= $row;
					$row = [];
				}

				// Adding the element to the row
				$row []= $element;
				$n_inserted_element -= -1;

				// Checking if the button must be alone into the row or if the row is full
				if ($full_row_flag || $n_inserted_element % 2 == 0) {
					// Adding the row to the keyboard
					$keyboard []= $row;
					$row = [];
				}
			}

			/*
			* Checking if the last row isn't empty
			*
			* empty() check if the row is empty
			* 	''
			* 	""
			* 	'0'
			* 	"0"
			* 	0
			* 	0.0
			* 	NULL
			* 	FALSE
			* 	[]
			* 	array()
			*/
			if (empty($row) == FALSE) {
				// Adding the row to the keyboard
				$keyboard []= $row;
			}

			// Checking if there are more then one page
			if ($page_dimension != 0) {
				$row = [];

				// Setting the "Previous page" button
				$control_buttons['text'] = $page_num != 0 ? '⬅️️ Pagina precedente' : '';
				$control_buttons['callback_data'] = $page_num != 0 ? $complete_path . '?p=' . ($page_num - 1) : '';
				$row []= $control_buttons;

				// Setting the "Next page" button
				$control_buttons['text'] = $page_num < $last_page ? 'Pagina successiva ➡️' : '';
				$control_buttons['callback_data'] = $page_num < $last_page ? $complete_path . '?p=' . ($page_num + 1) : '';
				$row []= $control_buttons;

				// Adding the control buttons to the keyboard
				$keyboard []= $row;
			}

			// Checking if the actual path isn't empty
			if (strlen($path) == 0) {
				// Setting the "Back" button
				$back = [
					'text' => '↩ Indietro',
					'callback_data' => substr($complete_path, 0, strrpos($complete_path, '/'))
				];

				// Adding the control buttons to the keyboard
				$keyboard []= $back;
			}
			return $keyboard;
		}

		/**
		* Check if the string ends with the substring
		*
		* @param string $haystack The string
		* @param string $needle The substring
		*
		* @return bool
		*/
		private function ends_with(string $haystack, string $needle) : bool {
			/**
			* strlen() retrieve the length of $needle
			* substr() retrieve the last strlen($needle)-th characters of $haystack
			*/
			return substr($haystack, -strlen($needle)) === $needle;
		}

		/**
		* Execute a web request to a URL
		*
		* @param string $url The URL
		* @param bool $parameters The flag that identify if the URL is a GET request with parameters
		*
		* @return string
		*/
		private function execute_request(string $url, bool $parameters = FALSE) : string {
			// Replace the special character into the URL
			$url = str_replace("\t", '%09', $url);
			$url = str_replace("\n", '%0A%0D', $url);
			$url = str_replace(' ', '%20', $url);
			$url = str_replace('\"', '%22', $url);
			$url = str_replace('#', '%23', $url);
			$url = str_replace([
				'$',
				'\$'
			], '%24', $url);
			$url = str_replace('%', '%25', $url);
			$url = str_replace('\'', '%27', $url);
			$url = str_replace(',', '%2C', $url);
			$url = str_replace(';', '%3B', $url);
			$url = str_replace('@', '%40', $url);

			// Checking if the URL isn't a GET request with parameters
			if ($parameters == FALSE) {
				$url = str_replace('=', '%3D', $url);
				$url = str_replace('?', '%3F', $url);
			}

			// Opening the connection
			$curl = curl_init($url);

			// Setting the connection
			curl_setopt_array($curl, [
				CURLOPT_HEADER => FALSE,
				CURLOPT_RETURNTRANSFER => TRUE
			]);

			// Executing the web request
			$result = curl_exec($curl);

			// Closing the connection
			curl_close($curl);

			return $result;
		}

		/**
		* Check if the string starts with the substring
		*
		* @param string $haystack The string
		* @param string $needle The substring
		*
		* @return bool
		*/
		private function starts_with(string $haystack, string $needle) : bool {
			/**
				* strlen() retrieve the length of $needle
				* substr() retrieve the first strlen($needle)-th characters of $haystack
			*/
			return substr($haystack, 0, strlen($needle)) === $needle;
		}

		/**
		* Get peer(s) where to report errors
		*
		* @return array
		*/
		public function getReportPeers() : array {
			return [
				0		// The log channel
			];
		}

		/**
		* Called on startup, can contain async calls for initialization of the bot
		*
		* @return void
		*/
		public function onStart() : void {
			// Retrieving the database
			$this::DB = json_decode(file_get_contents('database.json'), TRUE);
		}

		/**
		* Handle updates from Callback Query
		*
		* @param array $update Update
		*
		* @return Generator
		*/
		public function onUpdateBotCallbackQuery(array $update) : Generator {
			$callback_data = trim($update['data']);

			// Retrieving the data of the user that pressed the button
			$user = yield $this -> getInfo($update['user_id']);
			$user = $user['User'];

			// Checking if the user is a normal user
			if ($user['_'] !== 'user') {
				return;
			}

			// Retrieving the language of the user
			$language = isset($sender['lang_code']) ? $sender['lang_code'] : 'en';
			// Checking if the language is supported
			if (isset($this::DB[$language]) == FALSE) {
				$language = 'en';
			}

			// Setting the new keyboard
			$keyboard = [];
			// Checking if is a known query
			if ($this -> starts_with($callback_data, 'kb=')) {
				$callback_data = str_replace('kb=', '', $callback_data);
				$keyboard = ['inline_keyboard'] =  $this -> get_keyboard($callback_data, $language);
			} else {
				$keyboard = ['inline_keyboard'] =  $this -> get_keyboard('', $language);
			}

			try {
				yield $this -> messages -> editMessage([
					'no_webpage' => TRUE,
					'peer' => $user['id'],
					'id' => $update['msg_id'],
					'reply_markup' => $keyboard,
					'parse_mode' => 'HTML'
				]);
			} catch (danog\MadelineProto\RPCErrorException $e) {
				;
			}
		}

		/**
		* Handle updates from Inline Query
		*
		* @param array $update Update
		*
		* @return Generator
		*/
		public function onUpdateBotInlineQuery(array $update) : Generator {
			$inline_query = trim($update['query']);

			// Retrieving the data of the user that sent the query
			$user = yield $this -> getInfo($update['user_id']);
			$user = $user['User'];

			// Checking if the user is a normal user
			if ($user['_'] !== 'user') {
				return;
			}

			// Retrieving the language of the user
			$language = isset($sender['lang_code']) ? $sender['lang_code'] : 'en';
			// Checking if the language is supported
			if (isset($this::DB[$language]) == FALSE) {
				$language = 'en';
			}

			/*
			* Checking if the query isn't empty
			*
			* empty() check if the query is empty
			* 	''
			* 	""
			* 	'0'
			* 	"0"
			* 	0
			* 	0.0
			* 	NULL
			* 	FALSE
			* 	[]
			* 	array()
			*/
			if (empty($inline_query) == FALSE && strlen($inline_query) >= 2) {
				$answer = $this -> database_search($inline_query, $language);

				try {
					yield $this -> messages -> setInlineBotResults([
						'query_id' => $update['query_id'],
						'results' => $answer,
						'cache_time' => 1
					]);
				} catch (danog\MadelineProto\RPCErrorException $e) {
					;
				}
			}
		}

		/**
		* Handle updates about edited message from supergroups and channels
		*
		* @param array $update Update
		*
		* @return Generator
		*/
		public function onUpdateEditChannelMessage(array $update) : Generator {
			return $this -> onUpdateNewMessage($update);
		}

		/**
		* Handle updates about edited message from users
		*
		* @param array $update Update
		*
		* @return Generator
		*/
		public function onUpdateEditMessage(array $update) : Generator {
			return $this -> onUpdateNewMessage($update);
		}

		/**
		* Handle updates from supergroups and channels
		*
		* @param array $update Update
		*
		* @return Generator
		*/
		public function onUpdateNewChannelMessage(array $update) : Generator {
			return $this -> onUpdateNewMessage($update);
		}

		/**
		* Handle updates from users and groups
		*
		* @param array $update Update
		*
		* @return Generator
		*/
		public function onUpdateNewMessage(array $update) : Generator {
			$message = $update['message'];

			// Checking if the message is a normal message or is an incoming message
			if ($message['_'] === 'messageEmpty' || $message['out'] ?? FALSE) {
				return;
			}

			// Checking if the message is a service message
			if ($message['_'] === 'messageService') {
				// Checking if the service message is about new members
				if ($message['action']['_'] === 'messageActionChatAddUser') {
					// Cycle on the list of the new member
					foreach ($message['action']['users'] as $key => $value) {
						// Downloading the user's informations from the Combot Anti-Spam API
						$result = execute_request('https://api.cas.chat/check?user_id=' . $value, TRUE);
						$result = json_decode($result, TRUE);

						// Retrieving the data of the new member
						$new_member = yield $this -> getInfo($value);
						$new_member = $new_member['User'];

						// Checking if the user isn't a spammer, isn't a deleted account and is a normal user
						if ($result['ok'] == FALSE && $new_member['_'] === 'user' && $new_member['scam'] == FALSE && $new_member['deleted'] == FALSE) {
							continue;
						}

						try {
							yield $this -> channels -> editBanned([
								'channel' => $update['chat_id'],
								'user_id' => $value,
								'banned_rights' => [
									'_' => 'chatBannedRights',
									'view_messages' => TRUE,
									'send_messages' => TRUE,
									'send_media' => TRUE,
									'send_stickers' => TRUE,
									'send_gifs' => TRUE,
									'send_games' => TRUE,
									'send_inline' => TRUE,
									'embed_links' => TRUE,
									'send_polls' => TRUE,
									'change_info' => TRUE,
									'invite_users' => TRUE,
									'pin_messages' => TRUE,
									'until_date' => 0
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
					}
				} else if ($message['action']['_'] === 'messageActionChatJoinedByLink') {
					// Downloading the user's informations from the Combot Anti-Spam API
					$result = execute_request('https://api.cas.chat/check?user_id=' . $message['from_id'], TRUE);
					$result = json_decode($result, TRUE);

					// Retrieving the data of the new member
					$new_member = yield $this -> getInfo($message['from_id']);
					$new_member = $new_member['User'];

					// Checking if the user isn a spammer, isn a deleted account or isn't a normal user
					if ($result['ok'] || $new_member['_'] !== 'user' || $new_member['scam'] || $new_member['deleted']) {
						try {
							yield $this -> channels -> editBanned([
								'channel' => $update['chat_id'],
								'user_id' => $message['from_id'],
								'banned_rights' => [
									'_' => 'chatBannedRights',
									'view_messages' => TRUE,
									'send_messages' => TRUE,
									'send_media' => TRUE,
									'send_stickers' => TRUE,
									'send_gifs' => TRUE,
									'send_games' => TRUE,
									'send_inline' => TRUE,
									'embed_links' => TRUE,
									'send_polls' => TRUE,
									'change_info' => TRUE,
									'invite_users' => TRUE,
									'pin_messages' => TRUE,
									'until_date' => 0
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
					}
				}

				try {
					yield $this -> channels -> deleteMessages([
						'revoke' => TRUE,
						'id' => [
							$message['id']
						]
					]);
				} catch (danog\MadelineProto\RPCErrorException $e) {
					;
				}

				return;
			}

			$message['message'] = trim($message['message']);

			// Retrieving the data of the sender
			$sender = yield $this -> getInfo($message['from_id']);
			$sender = $sender['User'];

			// Checking if the user is a normal user
			if ($sender['_'] !== 'user') {
				return;
			}

			// Retrieving the language of the user
			$language = isset($sender['lang_code']) ? $sender['lang_code'] : 'en';
			// Checking if the language is supported
			if (isset($this::DB[$language]) == FALSE) {
				$language = 'en';
			}

			// Checking if is an @admin tag
			if (preg_match('/^\@admin([[:blank:]\n]{1}((\n|.)*))?$/miu', $message['message'], $matches)) {
				// Retrieving the admins list
				$chat = yield $this -> getPwrChat($message['to_id']);

				if ($chat['type'] != 'supergroup' && $chat['type'] == 'chat') {
					return;
				}

				$admins = array_filter($chat['participants'], function ($n) {
					return $n['role'] == 'admin' || $n['role'] == 'creator';
				});

				$admins = array_map(function ($n) {
					return $n['user'];
				}, $admins);

				// Creating the message to send to the admins
				$text = "\n<a href=\"mention:" . $sender['id'] . '\" >' . $sender['first_name'] . '</a> needs your help' . (($matches[2] ?? FALSE) ? ' for ' . $matches[2] : '') . ' into <a href=\"' . $chat['invite'] . '\" >' . $chat['title'] . '</a>.';

				foreach ($admins as $user) {
					try {
						yield $this -> messages -> sendMessage([
							'no_webpage' => TRUE,
							'peer' => $user['id'],
							'message' => '<a href=\"mention:' . $user['id'] . '\" >' . $user['first_name'] . '</a>,' . $text,
							'parse_mode' => 'HTML'
						]);
					} catch (danog\MadelineProto\RPCErrorException $e) {
						;
					}
				}

				// Sending the report to the channel
				$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> has sent an @admin request into <a href=\"' . $chat['exported_invite'] . '\" >' . $title . '</a>.');
			// Checking if is a Whatsapp link
			} else if (preg_match('/^(https?\:\/\/)?chat\.whatsapp\.com\/?.*$/.*$/miu', $message['message'])) {
				try {
					yield $this -> messages -> sendMessage([
						'no_webpage' => TRUE,
						'peer' => $message['to_id'],
						'message' => $this::DB[$language]['no_whatsapp'],
						'reply_to_msg_id' => $message['id'],
						'parse_mode' => 'HTML'
					]);

					yield $this -> channels -> deleteMessages([
						'revoke' => TRUE,
						'id' => [
							$message['id']
						]
					]);
				} catch (danog\MadelineProto\RPCErrorException $e) {
					;
				}
			// Checking if is a command
			} else if (preg_match('/^\/([[:alnum:]\@]+)[[:blank:]]?([[:alnum:]]|[^\n]+)?$/miu', $message['message'], $matches)) {
				// Retrieving the command
				$command = explode('@', $matches[1])[0];
				$args = $matches[2] ?? NULL;

				switch ($command) {
					case 'announce':
						// Checking if the command has arguments
						if (isset($args)) {
							/**
							* Checking if is a serious use of the /announce command (command runned in the staff group) and if the user is an admin of the bot
							*
							* in_array() check if the array contains an item that match the element
							*/
							if ($message['to_id'] == $this::DB['staff_group'] && in_array($sender['id'], $this::DB['admins'])) {
								$chats = yield $this -> getDialogs();

								// Cycle on the chats where the bot is present
								foreach ($chats as $peer) {
									try {
										yield $this -> messages -> sendMessage([
											'no_webpage' => TRUE,
											'peer' => $peer,
											'message' => $args,
											'parse_mode' => 'HTML'
										]);
									} catch (danog\MadelineProto\RPCErrorException $e) {
										;
									}
								}
								return;
							}

							// Retrieving the data of the chat
							$chat = yield $this -> getPwrChat($message['to_id']);

							// Checking if the chat is a group or a supergroup
							if ($chat['type'] != 'supergroup' && $chat['type'] == 'chat') {
								return;
							}

							/**
							* Retrieving the admins list
							*
							* array_filter() filters the array by the role of each member
							* array_map() convert each admins to its id
							*/
							$admins = array_filter($chat['participants'], function ($n) {
								return $n['role'] == 'admin' || $n['role'] == 'creator';
							});
							$admins = array_map(function ($n) {
								return $n['user']['id'];
							}, $admins);

							/**
							* Checking if the user is an admin and if the command has arguments
							*
							* in_array() check if the array contains an item that match the element
							*/
							if (in_array($sender['id'], $admins)) {
								try {
									yield $this -> messages -> sendMessage([
										'no_webpage' => TRUE,
										'peer' => $message['to_id'],
										'message' => $args,
										'parse_mode' => 'HTML'
									]);
								} catch (danog\MadelineProto\RPCErrorException $e) {
									;
								}
							}
						}

						try {
							yield $this -> channels -> deleteMessages([
								'revoke' => TRUE,
								'id' => [
									$message['id']
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
						break;
					case 'ban':
						/**
						* Checking if is a global use of the /ban command (command runned in the staff group) and if the user is an admin of the bot
						*
						* in_array() check if the array contains an item that match the element
						*/
						if ($message['to_id'] == $this::DB['staff_group'] && in_array($sender['id'], $this::DB['admins'])) {
							$chats = yield $this -> getDialogs();

							// Checking if the command has arguments
							if (isset($args) == FALSE) {
								try {
									yield $this -> messages -> sendMessage([
										'no_webpage' => TRUE,
										'peer' => $message['to_id'],
										'message' => 'The syntax of the command is: <code>/ban &lt;user_id|username&gt;</code>.',
										'reply_to_msg_id' => $message['id'],
										'parse_mode' => 'HTML'
									]);
								} catch (danog\MadelineProto\RPCErrorException $e) {
									;
								}
								return;
							}

							// Retrieving the data of the banned user
							$banned_user = yield $this -> getInfo($args);
							$banned_user = $banned_user['User'] ?? NULL;

							/*
							* Checking if the User is setted
							*
							* empty() check if the argument is empty
							* 	''
							* 	""
							* 	'0'
							* 	"0"
							* 	0
							* 	0.0
							* 	NULL
							* 	FALSE
							* 	[]
							* 	array()
							*/
							if (empty($banned_user) || $banned_user['_'] !== 'user') {
								try {
									yield $this -> messages -> sendMessage([
										'no_webpage' => TRUE,
										'peer' => $message['to_id'],
										'message' => 'The username/id is invalid.',
										'reply_to_msg_id' => $message['id'],
										'parse_mode' => 'HTML'
									]);
								} catch (danog\MadelineProto\RPCErrorException $e) {
									;
								}
								return;
							}

							// Cycle on the chats where the bot is present
							foreach ($chats as $peer) {
								try {
									yield $this -> channels -> editBanned([
										'channel' => $message['to_id'],
										'user_id' => $banned_user['id'],
										'banned_rights' => [
											'_' => 'chatBannedRights',
											'view_messages' => TRUE,
											'send_messages' => TRUE,
											'send_media' => TRUE,
											'send_stickers' => TRUE,
											'send_gifs' => TRUE,
											'send_games' => TRUE,
											'send_inline' => TRUE,
											'embed_links' => TRUE,
											'send_polls' => TRUE,
											'change_info' => TRUE,
											'invite_users' => TRUE,
											'pin_messages' => TRUE,
											'until_date' => 0
										]
									]);
								} catch (danog\MadelineProto\RPCErrorException $e) {
									;
								}
							}
							// Sending the report to the channel
							$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> banned <a href=\"mention:' . $banned_user['id'] . '\" >' . $banned_user['first_name'] . '</a> from all chats.');
							return;
						}

						// Retrieving the message this message replies to
						$reply_message = yield $this -> messages -> getMessages([
							'id' => [
								$message['reply_to_msg_id']
							]
						]);

						// Checking if the result is valid
						if ($reply_message['_'] === 'messages.messagesNotModified') {
							return;
						}

						$reply_message = $reply_message['messages'][0];

						// Retrieving the data of the banned user
						$banned_user = yield $this -> getInfo($reply_message['from_id']);
						$banned_user = $banned_user['User'];

						// Checking if the user is a normal user
						if ($banned_user['_'] !== 'user') {
							return;
						}

						try {
							yield $this -> channels -> editBanned([
								'channel' => $message['to_id'],
								'user_id' => $reply_message['from_id'],
								'banned_rights' => [
									'_' => 'chatBannedRights',
									'view_messages' => TRUE,
									'send_messages' => TRUE,
									'send_media' => TRUE,
									'send_stickers' => TRUE,
									'send_gifs' => TRUE,
									'send_games' => TRUE,
									'send_inline' => TRUE,
									'embed_links' => TRUE,
									'send_polls' => TRUE,
									'change_info' => TRUE,
									'invite_users' => TRUE,
									'pin_messages' => TRUE,
									'until_date' => 0
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}

						// Sending the report to the channel
						$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> banned <a href=\"mention:' . $banned_user['id'] . '\" >' . $banned_user['first_name'] . '</a>.');
						break;
					case 'exec':
						// Checking if the command has arguments
						if (isset($args) == FALSE) {
							try {
								yield $this -> messages -> sendMessage([
									'no_webpage' => TRUE,
									'peer' => $message['to_id'],
									'message' => 'The syntax of the command is: <code>/exec &lt;command&gt;</code>.',
									'reply_to_msg_id' => $message['id'],
									'parse_mode' => 'HTML'
								]);
							} catch (danog\MadelineProto\RPCErrorException $e) {
								;
							}

							return;
						}

						// Executing the command
						$output = shell_exec($args);

						try {
							yield $this -> messages -> sendMessage([
								'no_webpage' => TRUE,
								'peer' => $message['to_id'],
								'message' => '<b>Command:</b>\n\t<code>' . $args . '</code>\n\n<b>Result:</b>\n\t<code>' . $output . '</code>',
								'reply_to_msg_id' => $message['id'],
								'parse_mode' => 'HTML'
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
						break;
					case 'film':
						try {
							yield $this -> messages -> sendMessage([
								'no_webpage' => TRUE,
								'peer' => $message['to_id'],
								'message' => $this::DB[$language]['film'],
								'reply_to_msg_id' => $message['id'],
								'parse_mode' => 'HTML'
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
						break;
					case 'help':
						// Retrieving the data of the chat
						$chat = yield $this -> getPwrChat($message['to_id']);

						// Checking if the chat is a private chat
						if ($chat['type'] != 'user') {
							return;
						}

						try {
							yield $this -> messages -> sendMessage([
								'no_webpage' => TRUE,
								'peer' => $message['to_id'],
								'message' => $this::DB[$language]['help'],
								'reply_to_msg_id' => $message['id'],
								'parse_mode' => 'HTML',
								'reply_markup' => [
									'inline_keyboard' => $this -> get_keyboard('', $language)
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
						break;
					case 'kick':
						// Retrieving the data of the chat
						$chat = yield $this -> getInfo($message['to_id']);
						$chat = $chat['Chat'] ?? NULL;

						/*
						* Checking if the chat is setted
						*
						* empty() check if the argument is empty
						* 	''
						* 	""
						* 	'0'
						* 	"0"
						* 	0
						* 	0.0
						* 	NULL
						* 	FALSE
						* 	[]
						* 	array()
						*/
						if (empty($chat) || $chat['_'] !== 'chat' || $chat['_'] !== 'channel') {
							return;
						}

						// Retrieving the message this message replies to
						$reply_message = yield $this -> messages -> getMessages([
							'id' => [
								$message['reply_to_msg_id']
							]
						]);

						// Checking if the result is valid
						if ($reply_message['_'] === 'messages.messagesNotModified') {
							return;
						}

						$reply_message = $reply_message['messages'][0];

						// Retrieving the data of the kicked user
						$kicked_user = yield $this -> getInfo($reply_message['from_id']);
						$kicked_user = $kicked_user['User'];

						// Checking if the user is a normal user
						if ($kicked_user['_'] !== 'user') {
							return;
						}

						try {
							yield $this -> channels -> editBanned([
								'channel' => $message['to_id'],
								'user_id' => $reply_message['from_id'],
								'banned_rights' => [
									'_' => 'chatBannedRights',
									'view_messages' => TRUE,
									'send_messages' => TRUE,
									'send_media' => TRUE,
									'send_stickers' => TRUE,
									'send_gifs' => TRUE,
									'send_games' => TRUE,
									'send_inline' => TRUE,
									'embed_links' => TRUE,
									'send_polls' => TRUE,
									'change_info' => TRUE,
									'invite_users' => TRUE,
									'pin_messages' => TRUE,
									'until_date' => 0
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
						try {
							yield $this -> channels -> editBanned([
								'channel' => $message['to_id'],
								'user_id' => $reply_message['from_id'],
								'banned_rights' => $chat['default_banned_rights']
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}

						// Sending the report to the channel
						$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> kicked <a href=\"mention:' . $kicked_user['id'] . '\" >' . $kicked_user['first_name'] . '</a>.');
						break;
					case 'link':
						$chat = yield $this -> getPwrChat($message['to_id']);

						try {
							yield $this -> messages -> sendMessage([
								'no_webpage' => TRUE,
								'peer' => $message['to_id'],
								'message' => '<a href=\"' . $chat['invite'] . '\" >This</a> is the invite link to this chat.',
								'reply_to_msg_id' => $message['id'],
								'parse_mode' => 'HTML'
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
						break;
					case 'mute':
						// Setting limit to forever
						$limit = 0;

						// Checking if the command has arguments
						if (isset($args) && preg_match('/^([[:digit:]]+)[[:blank:]]?([[:alpha:]]+)$/miu', $args, $matches)) {
							$limit = $matches[1];

							switch ($matches[2]) {
								case 'm':
								case 'min':
								case 'minuto':
								case 'minute':
								case 'minuti':
								case 'minutes':
									$limit *= 60;
									break;
								case 'h':
								case 'ora':
								case 'hour':
								case 'ore':
								case 'hours':
									$limit *= 60 * 60;
									break;
								case 'g':
								case 'd':
								case 'giorno':
								case 'day':
								case 'giorni':
								case 'days':
									$limit *= 60 * 60 * 24;
									break;
								case 'M':
								case 'mese':
								case 'month':
								case 'mesi':
								case 'months':
								case 'a':
								case 'y':
								case 'anno':
								case 'year':
									$limit *= 60 * 60 * 24 * 12;
									break;
								default:
									try {
										yield $this -> messages -> sendMessage([
											'no_webpage' => TRUE,
											'peer' => $message['to_id'],
											'message' => "The syntax of the command is: <code>/mute [time]</code>.\nThe <code>time</code> option must be more then 30 seconds and less of 366 days.",
											'reply_to_msg_id' => $message['id'],
											'parse_mode' => 'HTML'
										]);
									} catch (danog\MadelineProto\RPCErrorException $e) {
										;
									}
									break;
							}
						}

						// Retrieving the message this message replies to
						$reply_message = yield $this -> messages -> getMessages([
							'id' => [
								$message['reply_to_msg_id']
							]
						]);

						// Checking if the result is valid
						if ($reply_message['_'] === 'messages.messagesNotModified') {
							return;
						}

						$reply_message = $reply_message['messages'][0];

						// Retrieving the data of the muted user
						$muted_user = yield $this -> getInfo($reply_message['from_id']);
						$muted_user = $muted_user['User'];

						// Checking if the user is a normal user
						if ($muted_user['_'] !== 'user') {
							return;
						}

						try {
							yield $this -> channels -> editBanned([
								'channel' => $message['to_id'],
								'user_id' => $reply_message['from_id'],
								'banned_rights' => [
									'_' => 'chatBannedRights',
									'view_messages' => FALSE,
									'send_messages' => TRUE,
									'send_media' => TRUE,
									'send_stickers' => TRUE,
									'send_gifs' => TRUE,
									'send_games' => TRUE,
									'send_inline' => TRUE,
									'embed_links' => TRUE,
									'send_polls' => TRUE,
									'change_info' => TRUE,
									'invite_users' => FALSE,
									'pin_messages' => TRUE,
									'until_date' => $limit
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}

						// Sending the report to the channel
						$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> muted <a href=\"mention:' . $muted_user['id'] . '\" >' . $muted_user['first_name'] . '</a>.');
						break;
					case 'report':
						// Retrieving the data of the chat
						$chat = yield $this -> getPwrChat($message['to_id']);

						// Checking if the chat is a private chat
						if ($chat['type'] != 'user') {
							return;
						}

						/**
						* Checking if the user is an admin
						*
						* in_array() check if the array contains an item that match the element
						*/
						if (in_array($sender['id'], $this::DB['admins']) == FALSE) {
							try {
								yield $this -> messages -> sendMessage([
									'no_webpage' => TRUE,
									'peer' => $message['to_id'],
									'message' => 'You can\'t use this command.',
									'reply_to_msg_id' => $message['id'],
									'parse_mode' => 'HTML'
								]);
							} catch (danog\MadelineProto\RPCErrorException $e) {
								;
							}

							return;
						}

						/**
						* Retrieving the commands list and converting it into an array which element are a botCommand element
						*
						* array_map() converts the array by applying the closures to its elements
						*/
						$commands = array_map(function ($n) {
							return [
								'_' => 'botCommand',
								'command' => $n['command'],
								'description' => $n['description']
							];
						}, $this::DB['commands']);

						yield $this -> bots -> setBotCommands([
							'commands' => $commands
						]);
						break;
					case 'start':
						// Retrieving the data of the chat
						$chat = yield $this -> getPwrChat($message['to_id']);

						// Checking if the chat is a private chat
						if ($chat['type'] != 'user') {
							return;
						}

						try {
							yield $this -> messages -> sendMessage([
								'no_webpage' => TRUE,
								'peer' => $message['to_id'],
								'message' => str_replace('${sender_first_name}', $sender['first_name'], $this::DB[$language]['welcome']),
								'reply_to_msg_id' => $message['id'],
								'parse_mode' => 'HTML',
								'reply_markup' => [
									'inline_keyboard' => $this -> get_keyboard('', $language)
								]
							]);
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}
						break;
					case 'unban':
						/**
						* Checking if is a global use of the /unban command (command runned in the staff group) and if the user is an admin of the bot
						*
						* in_array() check if the array contains an item that match the element
						*/
						if ($message['to_id'] == $this::DB['staff_group'] && in_array($sender['id'], $this::DB['admins'])) {
							$chats = yield $this -> getDialogs();

							// Checking if the command has arguments
							if (isset($args) == FALSE) {
								try {
									yield $this -> messages -> sendMessage([
										'no_webpage' => TRUE,
										'peer' => $message['to_id'],
										'message' => 'The syntax of the command is: <code>/unban &lt;user_id|username&gt;</code>.',
										'reply_to_msg_id' => $message['id'],
										'parse_mode' => 'HTML'
									]);
								} catch (danog\MadelineProto\RPCErrorException $e) {
									;
								}
								return;
							}

							// Retrieving the data of the banned user
							$unbanned_user = yield $this -> getInfo($args);
							$unbanned_user = $unbanned_user['User'] ?? NULL;

							/*
							* Checking if the User is setted
							*
							* empty() check if the argument is empty
							* 	''
							* 	""
							* 	'0'
							* 	"0"
							* 	0
							* 	0.0
							* 	NULL
							* 	FALSE
							* 	[]
							* 	array()
							*/
							if (empty($unbanned_user) || $unbanned_user['_'] !== 'user') {
								try {
									yield $this -> messages -> sendMessage([
										'no_webpage' => TRUE,
										'peer' => $message['to_id'],
										'message' => 'The username/id is invalid.',
										'reply_to_msg_id' => $message['id'],
										'parse_mode' => 'HTML'
									]);
								} catch (danog\MadelineProto\RPCErrorException $e) {
									;
								}
								return;
							}

							// Cycle on the chats where the bot is present
							foreach ($chats as $peer) {
								// Retrieving the data of the chat
								$chat = yield $this -> getInfo($peer);
								$chat = $chat['Chat'] ?? NULL;

								/*
								* Checking if the chat is setted
								*
								* empty() check if the argument is empty
								* 	''
								* 	""
								* 	'0'
								* 	"0"
								* 	0
								* 	0.0
								* 	NULL
								* 	FALSE
								* 	[]
								* 	array()
								*/
								if (empty($chat) || $chat['_'] !== 'chat' || $chat['_'] !== 'channel') {
									continue;
								}

								try {
									yield $this -> channels -> editBanned([
										'channel' => $message['to_id'],
										'user_id' => $unbanned_user['id'],
										'banned_rights' => $chat['default_banned_rights']
									]);
								} catch (danog\MadelineProto\RPCErrorException $e) {
									;
								}
							}
							// Sending the report to the channel
							$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> unbanned <a href=\"mention:' . $banned_user['id'] . '\" >' . $banned_user['first_name'] . '</a> from all chats.');
							return;
						}

						// Retrieving the data of the chat
						$chat = yield $this -> getInfo($message['to_id']);
						$chat = $chat['Chat'] ?? NULL;

						/*
						* Checking if the chat is setted
						*
						* empty() check if the argument is empty
						* 	''
						* 	""
						* 	'0'
						* 	"0"
						* 	0
						* 	0.0
						* 	NULL
						* 	FALSE
						* 	[]
						* 	array()
						*/
						if (empty($chat) || $chat['_'] !== 'chat' || $chat['_'] !== 'channel') {
							return;
						}

						// Retrieving the message this message replies to
						$reply_message = yield $this -> messages -> getMessages([
							'id' => [
								$message['reply_to_msg_id']
							]
						]);

						// Checking if the result is valid
						if ($reply_message['_'] === 'messages.messagesNotModified') {
							return;
						}

						$reply_message = $reply_message['messages'][0];

						// Retrieving the data of the unbanned user
						$unbanned_user = yield $this -> getInfo($reply_message['from_id']);
						$unbanned_user = $unbanned_user['User'];

						// Checking if the user is a normal user
						if ($unbanned_user['_'] !== 'user') {
							return;
						}

						try {
							yield $this -> channels -> editBanned([
								'channel' => $message['to_id'],
								'user_id' => $reply_message['from_id'],
								'banned_rights' => $chat['default_banned_rights']
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}

						// Sending the report to the channel
						$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> unbanned <a href=\"mention:' . $unbanned_user['id'] . '\" >' . $unbanned_user['first_name'] . '</a>.');
						break;
					case 'unmute':
						// Retrieving the data of the chat
						$chat = yield $this -> getInfo($message['to_id']);
						$chat = $chat['Chat'] ?? NULL;

						/*
						* Checking if the chat is setted
						*
						* empty() check if the argument is empty
						* 	''
						* 	""
						* 	'0'
						* 	"0"
						* 	0
						* 	0.0
						* 	NULL
						* 	FALSE
						* 	[]
						* 	array()
						*/
						if (empty($chat) || $chat['_'] !== 'chat' || $chat['_'] !== 'channel') {
							return;
						}

						// Retrieving the message this message replies to
						$reply_message = yield $this -> messages -> getMessages([
							'id' => [
								$message['reply_to_msg_id']
							]
						]);

						// Checking if the result is valid
						if ($reply_message['_'] === 'messages.messagesNotModified') {
							return;
						}

						$reply_message = $reply_message['messages'][0];

						// Retrieving the data of the unmuted user
						$unmuted_user = yield $this -> getInfo($reply_message['from_id']);
						$unmuted_user = $unmuted_user['User'];

						// Checking if the user is a normal user
						if ($unmuted_user['_'] !== 'user') {
							return;
						}

						try {
							yield $this -> channels -> editBanned([
								'channel' => $message['to_id'],
								'user_id' => $reply_message['from_id'],
								'banned_rights' => $chat['default_banned_rights']
						} catch (danog\MadelineProto\RPCErrorException $e) {
							;
						}

						// Sending the report to the channel
						$this -> report('<a href=\"mention:' . $sender['id'] . '\" >' . $sender['first_name'] . '</a> unmuted <a href=\"mention:' . $unmuted_user['id'] . '\" >' . $unmuted_user['first_name'] . '</a>.');
						break;
					default:
						break;
				}
			}
		}
	}

	$MadelineProto = new danog\MadelineProto\API('inginf_bot.madeline', [
		'app_info' => [
			'lang_code' => 'en'
		],
		'logger' => [
			'logger' => danog\MadelineProto\Logger::FILE_LOGGER,
			'logger_level' => danog\MadelineProto\Logger::ULTRA_VERBOSE,
			'param' => '/log/inginf_bot.log'
		]
	]);

	// Setting the bot
	yield $MadelineProto -> botLogin(getenv('BOT_TOKEN'));
	yield $MadelineProto -> async(TRUE);

	// Starting the bot
	$MadelineProto -> startAndLoop(inginf_bot::class);

	exit(0);
?>
