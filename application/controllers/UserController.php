<?php

class UserController extends Zend_Controller_Action
{

    public function signupAction()
    {

		$request = $this->getRequest();

		if($request->isPost()) {

			$username = $request->getParam('username');
			$password = $request->getParam('password');
			$retype = $request->getParam('retype');


			if( strlen($password) < 4 )
				throw new Exception('Password should bb more than 3 character');

			if($password != $retype)
				throw new Exception('password and retype are not matched together.');


			$m = new MongoClient(); // connect
			$db = $m->selectDB("twitter");
			$collection = $db->user;

			$search_array = array( 'username' => $username);
			$return_array = array('username', 'password');

			$old_user = $collection->findOne($search_array, $return_array);

			if(!empty($old_user)) {

				$_SESSION['error'] = true;
				$_SESSION['message'] = "This username is already occupied. please choose another username.";
				$this->_helper->redirector('signup');
			}
			$document= array(
				"username" => $username,
				"password" => $password,
				'favorites' => 0
			);

			$collection->insert( $document );
			$_SESSION['success'] = true;
			$_SESSION['message'] = "User is signed up successfully.";

		}
    }

	public function loginAction() {

		session_start();
		$request = $this->getRequest();

		if($request->isPost()) {

			$username = $request->getParam('username');
			$password = $request->getParam('password');


			$m = new MongoClient(); // connect
			$db = $m->selectDB("twitter");
			$collection = $db->user;

			$search_array = array( 'username' => $username, 'password' => $password);
			$return_array = array('username', 'password', '_id');

			$user = $collection->findOne($search_array, $return_array);

			if( empty($user) ) {

				$_SESSION['error'] = true;
				$_SESSION['message'] = "Login failed. username or password is wrong.";
			}

			else {
				$_SESSION['islogin'] = true;
				$_SESSION['islogin']['username'] = $username;

				$this->_helper->redirector('home', 'user', NULL, array('username' => $username));
			}
		}

	}

	public function profileAction() {

		$request = $this->getRequest();
		$username = $request->getParam('username');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");

		$user_collection = $db->user;
		$post_collection = $db->post;

		$search_array = array('owner_username' => $username);
		$return_array = array('text', 'favorites', "_id", 'comments');

		$cursor = $post_collection->find( $search_array, $return_array);


		$search_array = array('username' => $username);
		$return_array = array('username', 'favorites', "_id", 'comments', 'followers', 'followed');

		$user = $user_collection->findOne( $search_array, $return_array);

		$this->view->username = $username;
		$this->view->user = $user;
		$this->view->posts = $cursor;

	}

	public function postAction() {

		$request = $this->getRequest();

		$username = $request->getParam('username');
		$post_text = $request->getParam('text');

		if( empty($post_text))
			throw new Exception('Post should not be empty');

		if (strlen($post_text) > 140)
			throw new Exception('Your post has more than 140 characters.');

		$m = new MongoClient();
		$db = $m->twitter;

		$user_collection = $db->user;

		$search_array = array( 'username' => $username);
		$return_array = array('username', 'password');

		$user = $user_collection->findOne($search_array, $return_array);

		if (empty($user))
			throw new Exception("username is wrong");

		$post_collection = $db->post;

		$document = array(
			"text" => $post_text,
			"owner_username" => $username
		);

		$post_collection->insert($document);
		$post_id = $document['_id'];

		$hashtag_collection = $db->hashtag;

		// Add hashtags
		$words = $this->getWords($post_text);
		foreach($words as $word) {
			$hash_doc = array(
				'post_id' => $post_id,
				'hashtag' => substr($word, 1)
			);

			$hashtag_collection->insert($hash_doc);
		}

		$this->_helper->redirector('profile', 'user', NULL, array('username' => $username));
	}

	public function commentAction() {

		$request = $this->getRequest();

		$username = $request->getParam('username');
		$post_id = $request->getParam('post_id');
		$comment = $request->getParam('comment');
		$redirection_page = $request->getParam('type');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");
		$collection = $db->post;

		$mongoID = new MongoID($post_id);

		$search_array = array( '_id' => $mongoID);
		$return_array = array('_id', 'username', 'text', 'comments', 'owner_username');

		$post = $collection->findOne($search_array, $return_array);

		$old_comments = $post['comments'];

		$new_comment = array();
		$new_comment[0] = array('username'=> $username, 'comment_text'=> $comment);

		if(is_array($old_comments))
			$new_comments = array_merge($old_comments , $new_comment);
		else $new_comments = $new_comment;
		$new_data = array(
			'$set' => array(
				'comments' => $new_comments
			)
		);

		$collection->update($search_array, $new_data);
		switch($redirection_page) {

			case 1: $this->_helper->redirector('profile', 'user', NULL, array('username' => $username));break;
			case 2: $this->_helper->redirector('friend', 'user', NULL, array('username' => $post['owner_username'], 'viewer'=> $username));break;
			default: $this->_helper->redirector('home', 'user', NULL, array('username' => $username));
		}

	}

	public function likeAction() {

		$request = $this->getRequest();

		$post_id = $request->getParam('post_id');
		$username = $request->getParam('username');
		$redirection_page = $request->getParam('type');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");
		$collection = $db->post;

		$mongoID = new MongoID($post_id);

		$search_array = array( '_id' => $mongoID);
		$return_array = array('_id', 'owner_username', 'text', 'comments', 'favorites');

		$post = $collection->findOne($search_array, $return_array);

		$old_favorites = $post['favorites'];

		foreach($old_favorites as $entry) {

			if($entry['username'] == $username) {
				$_SESSION['error'] = true;
				$_SESSION['message'] = "You've already liked this tweet!";
				switch($redirection_page) {

					case 1: $this->_helper->redirector('profile', 'user', NULL, array('username' => $username));break;
					case 2: $this->_helper->redirector('friend', 'user', NULL, array('username' => $post['owner_username'], 'viewer'=> $username));break;
					default: $this->_helper->redirector('home', 'user', NULL, array('username' => $username));
				}
			}

		}


		$new_favourite = array();
		$new_favourite[0] = array('username'=> $username);

		if(is_array($old_favorites))
			$new_favorites = array_merge($old_favorites , $new_favourite);
		else $new_favorites = $new_favourite;
		$new_data = array(
			'$set' => array(
				'favorites' => $new_favorites
			)
		);

		$collection->update($search_array, $new_data);

		$user_collection = $db->user;
		$search_array = array( 'username' => $username);
		$return_array = array('_id', 'username', 'favorites');

		$user = $user_collection->findOne($search_array, $return_array);
		if(empty($user['favorites']))
			$new_favor = 1;
		else $new_favor = $user['favorites'] + 1;
		$new_data = array(
			'$set' => array(
				'favorites' => $new_favor
			)
		);
		$user_collection->update($search_array, $new_data);


		switch($redirection_page) {

			case 1: $this->_helper->redirector('profile', 'user', NULL, array('username' => $username));break;
			case 2: $this->_helper->redirector('friend', 'user', NULL, array('username' => $post['owner_username'], 'viewer'=> $username));break;
			default: $this->_helper->redirector('home', 'user', NULL, array('username' => $username));
		}

	}

	public function followAction() {

		$request = $this->getRequest();
		$follower = $request->getParam('follower_user');
		$followed = $request->getParam('followed_user');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");
		$collection = $db->user;


		$follower_array = array( 'username' => $follower);
		$followed_array = array( 'username' => $followed);
		$return_array = array('_id', 'username', 'text', 'followers', 'followed');

		$follower_user = $collection->findOne($follower_array, $return_array);

		$followed_user = $collection->findOne($followed_array, $return_array);

		$old_followers = $followed_user['followers'];

		$new_follower = array();
		$new_follower[0] = array('username' => $follower);

		if(is_array($old_followers)) {

			foreach($old_followers as $old_follower)
				if($old_follower['username'] == $follower)
					return $this->_helper->redirector('friend', 'user', NULL, array('username' => $followed, 'viewer'=> $follower));

			$new_followers = array_merge($old_followers , $new_follower);
		}
		else $new_followers = $new_follower;
		$new_data = array(
			'$set' => array(
				'followers' => $new_followers
			)
		);

		$collection->update($followed_array, $new_data);

		// add to follower db
		$old_followed = $follower_user['followed'];

		$new_followed = array();
		$new_followed[0] = array('username'=> $followed);

		if(is_array($old_followed))
			$new_followers = array_merge($old_followed , $new_followed);
		else $new_followers = $new_followed;
		$new_data = array(
			'$set' => array(
				'followed' => $new_followers
			)
		);

		$collection->update($follower_array, $new_data);
		$this->_helper->redirector('friend', 'user', NULL, array('username' => $followed, 'viewer'=> $follower));
	}


	public function searchAction() {

		$request = $this->getRequest();
		$name = $request->getParam('name');
		$viewer = $request->getParam('viewer');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");
		$collection = $db->user;

		$user = $collection->findOne(array('username' => $name), array('username'));


		if(empty($user)) {
			$_SESSION['error'] = true;
			$_SESSION['message'] = "user not found";
			$this->_helper->redirector('profile', 'user', NULL, array('username' => $name));
		}


		$this->_helper->redirector('friend', 'user', NULL, array('username' => $name, 'viewer'=> $viewer));
	}

	public function friendAction() {

		$request = $this->getRequest();

		$username = $request->getParam('username');
		$viewer = $request->getParam('viewer');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");
		$user_collection = $db->user;


		$user_array = array('username' => $username);
		$viewer_array = array('username' => $viewer);


		$return_array = array('username', 'password', 'followers', 'followed', 'favorites');

		$user = $user_collection->findOne($user_array, $return_array);
		$viewer = $user_collection->findOne($viewer_array, $return_array);

		if(empty($viewer))
			throw new Exception(" empty viewer");

		$post_collection = $db->post;

		$search_array = array('owner_username' => $username);
		$return_array = array('text', 'favorites', "_id", 'comments');

		$cursor = $post_collection->find( $search_array, $return_array);

		$this->view->posts = $cursor;
		$this->view->user = $user;
		$this->view->username = $username;
		$this->view->viewer = $viewer['username'];

	}

	public function followersAction() {

		$request = $this->getRequest();
		$request->getParam('username');

		$username = $request->getParam('username');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");
		$user_collection = $db->user;


		$user_array = array('username' => $username);

		$return_array = array('username', 'followers');

		$user = $user_collection->findOne($user_array, $return_array);

		$this->view->followers = $user['followers'];
		$this->view->username = $username;
	}

	public function homeAction() {

		$request = $this->getRequest();

		$username =$request->getParam('username');


		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");

		$user_collection = $db->user;
		$post_collection = $db->post;

		$user_array = array('username' => $username);
		$return_array = array('username', 'followed');

		$user = $user_collection->findOne($user_array, $return_array);
		$followed_by_user = $user['followed'];

		$post_array = array();
		foreach( $followed_by_user as $followed) {

			$followed_user = $followed['username'];

			$search_array = array('owner_username' => $followed_user);
			$return_array = array('text', 'favorites', "_id", 'comments', 'owner_username');
			$cursor = $post_collection->find( $search_array, $return_array);

			foreach($cursor as $post)
				$post_array[] = $post;
		}

		$this->view->posts = $post_array;
		$this->view->username = $username;
	}


	private function getWords($subject) {

		$words = array();

		$len = strlen($subject);

		$total_tags = 0;
		for($i = 0; $i<$len; $i++)
			if($subject[$i] == '#') $total_tags++;

		$i = 0;
		for($x = 0; $x<$total_tags; $x++) {


			while( $subject[$i] != '#')
				$i++;

			$j = $i;

			while($subject[$j] != ' ' && $j <= $len)
				$j++;

			$string = substr($subject, $i-1, $j-$i+1);
			$words[] = $string;

			$i++;

		}

		return $words;
	}

	public function getpostAction() {

		$request = $this->getRequest();
		$word = $request->getParam('word');

		$m = new MongoClient(); // connect
		$db = $m->selectDB("twitter");

		$hashtag_collection = $db->hashtag;
		$post_collection = $db->post;

		$search_word = '#' . $word;
		$search_array = array('hashtag' => $search_word);
		$return_array = array('post_id', 'hashtag');

		$cursor = $hashtag_collection->find($search_array, $return_array);

		$posts = array();

		foreach( $cursor as $post_id) {

			$post =	$post_collection->findOne(array('_id' => new MongoId($post_id['post_id']) ) , array('text', 'favorites', "_id", 'comments', 'owner_username'));
			$posts[] = $post;
		}

		$this->view->posts = $posts;

	}
}