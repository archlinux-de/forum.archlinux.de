<?php

$request = 'index.php';

if (isset($_GET['page'])) {
	switch ($_GET['page']) {
		case 'ForgotPassword':
			$request = 'login.php?action=forget';
			break;
		case 'Forums':
			$request = 'index.php';
			break;
		case 'GetRecent':
			$request = 'extern.php?action=feed&type=atom&show=25&order=posted';
			if (isset($_GET['forum'])) {
				$request .= '&fid='.intval($_GET['forum']);
			}
			break;
		case 'Login':
			$request = 'login.php';
			break;
		case 'MarkupHelp':
			$request = 'help.php';
			break;
		case 'NewPost':
			if (isset($_GET['thread'])) {
				$request = 'post.php?tid='.intval($_GET['thread']);
			}
			break;
		case 'NewThread':
			if (isset($_GET['forum'])) {
				$request = 'post.php?fid='.intval($_GET['forum']);
			}
			break;
		case 'Postings':
			if (isset($_GET['thread'])) {
				$request = 'viewtopic.php?id='.intval($_GET['thread']);
				if (isset($_GET['post'])) {
					$request .= '&p='.(floor(intval($_GET['post']) / 25) + 1);
				}
			}
			break;
		case 'QuotePost':
			if (isset($_GET['post'])) {
				$request = 'viewtopic.php?pid='.intval($_GET['post']).'#p'.intval($_GET['post']);
			}
			break;
		case 'Recent':
			$request = 'search.php?action=show_24h';
			break;
		case 'Register':
			$request = 'register.php';
			break;
		case 'Search':
			$request = 'search.php';
			if (isset($_GET['search'])) {
				$request .= '?action=search&show_as=topics&keywords='.urlencode($_GET['search']);
			}
			break;
		case 'SearchResults':
			$request = 'search.php';
			break;
		case 'ShowUser':
			if (isset($_GET['user'])) {
				$request = 'profile.php?id='.intval($_GET['user']);
			}
			break;
		case 'Threads':
			if (isset($_GET['forum'])) {
				$request = 'viewforum.php?id='.intval($_GET['forum']);
				if (isset($_GET['thread'])) {
					$request .= '&p='.(floor(intval($_GET['thread']) / 30) + 1);
				}
			}
			break;
		case 'UserRecent':
			if (isset($_GET['user'])) {
				$request = 'search.php?action=show_user&user_id='.intval($_GET['user']);
			}
			break;
		default:
			header('HTTP/1.1 404 Not Found');
			echo '<html><head><title>Not Found</title></head><body><p>Page <strong>'.htmlspecialchars($_GET['page']).'</strong> was not found</p></body></html>';
			exit();
	}
}

$redirect = 'https://bbs.archlinux.de/'.$request;

header('HTTP/1.1 301 Moved Permanently');
header('Location: '.$redirect);

echo '<html><head><title>Moved Permanently</title></head><body><p>Moved permanently to <a href="'.htmlspecialchars($redirect).'">'.htmlspecialchars($redirect).'</a></p></body></html>';
exit();
