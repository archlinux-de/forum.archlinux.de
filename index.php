<?php

$path = 'index.php';

if (isset($_GET['page'])) {
	switch ($_GET['page']) {
		case 'ForgotPassword':
			$path = 'login.php?action=forget';
			break;
		case 'Forums':
			$path = 'index.php';
			break;
		case 'GetRecent':
			$path = 'extern.php?action=feed&type=atom&show=25&order=posted';
			if (isset($_GET['forum'])) {
				$path .= '&fid=' . intval($_GET['forum']);
			}
			break;
		case 'Login':
			$path = 'login.php';
			break;
		case 'MarkupHelp':
			$path = 'help.php';
			break;
		case 'NewPost':
			if (isset($_GET['thread'])) {
				$path = 'post.php?tid=' . intval($_GET['thread']);
			}
			break;
		case 'NewThread':
			if (isset($_GET['forum'])) {
				$path = 'post.php?fid=' . intval($_GET['forum']);
			}
			break;
		case 'Postings':
			if (isset($_GET['thread'])) {
				$path = 'viewtopic.php?id=' . intval($_GET['thread']);
				if (isset($_GET['post'])) {
					$path .= '&p=' . (floor(intval($_GET['post']) / 25) + 1);
				}
			}
			break;
		case 'QuotePost':
			if (isset($_GET['post'])) {
				$path = 'viewtopic.php?pid=' . intval($_GET['post']) . '#p' . intval($_GET['post']);
			}
			break;
		case 'Recent':
			$path = 'search.php?action=show_24h';
			break;
		case 'Register':
			$path = 'register.php';
			break;
		case 'Search':
			$path = 'search.php';
			if (isset($_GET['search'])) {
				$path .= '?action=search&show_as=topics&keywords=' . urlencode($_GET['search']);
			}
			break;
		case 'SearchResults':
			$path = 'search.php';
			break;
		case 'ShowUser':
			if (isset($_GET['user'])) {
				$path = 'profile.php?id=' . intval($_GET['user']);
			}
			break;
		case 'Threads':
			if (isset($_GET['forum'])) {
				$path = 'viewforum.php?id=' . intval($_GET['forum']);
				if (isset($_GET['thread'])) {
					$path .= '&p=' . (floor(intval($_GET['thread']) / 30) + 1);
				}
			}
			break;
		case 'UserRecent':
			if (isset($_GET['user'])) {
				$path = 'search.php?action=show_user&user_id=' . intval($_GET['user']);
			}
			break;
		default:
			http_response_code(404);
			exit();
	}
}

http_response_code(301);
header(sprintf('Location: https://bbs.archlinux.de/%s', $path));
