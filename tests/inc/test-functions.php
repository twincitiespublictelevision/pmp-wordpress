<?php

class TestFunctions extends WP_UnitTestCase {
	function setUp() {
		parent::setUp();

		$settings = get_option('pmp_settings');

		if (empty($settings['pmp_api_url']) || empty($settings['pmp_client_id']) || empty($settings['pmp_client_secret']))
			$this->skip = true;
		else {
			$this->skip = false;
			$this->sdk_wrapper = new SDKWrapper();

			// A test query that's all but guaranteed to return at least one result.
			$this->query = array(
				'text' => 'Obama',
				'limit' => 10,
				'profile' => 'story'
			);

			$this->editor = $this->factory->user->create();
			$user = get_user_by('id', $this->editor);
			$user->set_role('editor');
			wp_set_current_user($user->ID);

			$result = $this->sdk_wrapper->query2json('queryDocs', $this->query);
			$this->pmp_story = $result['items'][0];
			$_POST['post_data'] = addslashes(json_encode($this->pmp_story));
			$ret = _pmp_create_post();

			$this->post = $this->factory->post->create(array(
				'post_title' => 'WP PMP Unit Test Story'
			));
			$this->attachment = $this->factory->post->create(array('post_type' => 'attachment'));
		}

	}

	function test_pmp_render_template() {
		$this->expectOutputRegex('/<h2>Search the Platform<\/h2>/');
		pmp_render_template('search.php', array(
			'PMP' => array(),
			'creators' => array(),
			'profiles' => array()
		));
	}

	function test_pmp_get_creators() {
		$creators = pmp_get_creators();
		$expected_creators = array(
			'APM' => '98bf597a-2a6f-446c-9b7e-d8ae60122f0d',
			'NPR' => '6140faf0-fb45-4a95-859a-070037fafa01',
			'PBS' => 'fc53c568-e939-4d9c-86ea-c2a2c70f1a99',
			'PRI' => '7a865268-c9de-4b27-a3c1-983adad90921',
			'PRX' => '609a539c-9177-4aa7-acde-c10b77a6a525'
		);

		$this->assertTrue(count($creators) == count($expected_creators));

		foreach (array_keys($expected_creators) as $key)
			$this->assertTrue(in_array($key, array_keys($creators)));

		foreach (array_values($expected_creators) as $val)
			$this->assertTrue(in_array($val, array_values($creators)));
	}

	function test_pmp_get_profiles() {
		$profiles = pmp_get_profiles();
		$expected_profiles = array(
			'Story' => 'story',
			'Audio' => 'audio',
			'Video' => 'video',
			'Image' => 'image',
			'Series' => 'series',
			'Episode' => 'episode'
		);

		$this->assertTrue(count($profiles) == count($expected_profiles));

		foreach (array_keys($expected_profiles) as $key)
			$this->assertTrue(in_array($key, array_keys($profiles)));

		foreach (array_values($expected_profiles) as $val)
			$this->assertTrue(in_array($val, array_values($profiles)));
	}

	function test_pmp_media_sideload_image() {
		$new_post = $this->factory->post->create();
		$url = 'http://publicmediaplatform.org/wp-content/uploads/logo1.png';
		$desc = 'Test description';

		$image_id = pmp_media_sideload_image($url, $new_post, $desc);
		$this->assertTrue(!is_wp_error($image_id));

		$attachment = get_post($image_id);
		$this->assertEquals($desc, $attachment->post_title);
	}

	function test_pmp_verify_settings() {
		// Since we're setting the pmp_settings in bootstrap.php, this
		// should return true
		$this->assertTrue(pmp_verify_settings());
	}

	function test_pmp_on_post_status_transition() {
		$pmp_posts = pmp_get_pmp_posts();
		$pmp_post = $pmp_posts[0];
		$custom_fields = get_post_custom($pmp_post->ID);
		$date = date('Y-m-d H:i:s', strtotime($custom_fields['pmp_published'][0]));

		// Transition to published
		wp_publish_post($pmp_post->ID);
		$pmp_post_after_transition = get_post($pmp_post->ID);

		// The date should be the same as the original PMP published date, not the current date/time.
		$this->assertEquals($pmp_post_after_transition->post_date, $date);
	}

	function test_pmp_publish_and_push_to_pmp_button() {
		global $post;

		$tmp_post = $post;
		$post = get_post($this->post);
		setup_postdata($post);

		$this->expectOutputRegex('/value="(.*) and push to PMP"/');
		pmp_publish_and_push_to_pmp_button();

		$post = $tmp_post;
		wp_reset_postdata();
	}

	function test_pmp_push_to_pmp() {
		$post = get_post($this->post);

		// Since `pmp_push_to_pmp` is run when the post edit form is
		// submitted, we have to set $_POST['pmp_publish_push']
		// for this to work.
		$_POST['pmp_publish_push'] = true;
		$guid = pmp_push_to_pmp($this->post);

		$pmp_story = $this->sdk_wrapper->fetchDoc($guid);
		$this->assertEquals($post->post_title, $pmp_story->attributes->title);

		// Clean up
		$pmp_story->delete();
	}

	function test_pmp_handle_push() {
		$this->markTestSkipped(
			'Functional test of `pmp_handle_push` performed by `test_pmp_push_to_pmp`');
	}

	function test_pmp_enclosures_for_media() {
		$new_post = $this->factory->post->create();
		$url = 'http://publicmediaplatform.org/wp-content/uploads/logo1.png';
		$desc = 'Test description';

		$image_id = pmp_media_sideload_image($url, $new_post, $desc);
		$enclosures = pmp_enclosures_for_media($image_id);

		$this->assertTrue((bool) count($enclosures));

		$first_enc = $enclosures[0];

		// These should be present at the first level of the returned array
		$expected_keys_first = array('href', 'meta', 'type');
		foreach ($expected_keys_first as $expected_key)
			$this->assertTrue(in_array($expected_key, array_keys((array) $first_enc)));

		// The meta array should have these keys, indicating the crop and size of the image
		$expected_keys_second = array('crop', 'width', 'height');
		foreach ($expected_keys_second as $expected_key)
			$this->assertTrue(in_array($expected_key, array_keys((array) $first_enc->meta)));
	}

	function test_pmp_post_is_mine() {
		$is_mine = pmp_post_is_mine($this->post);
		$this->assertTrue($is_mine);

		$pmp_posts = pmp_get_pmp_posts();
		$pmp_post = $pmp_posts[0];
		$is_mine = pmp_post_is_mine($pmp_post->ID);
		$this->assertTrue(!$is_mine);
	}

	function test_pmp_get_post_data_from_pmp_doc() {
		$post_data = pmp_get_post_data_from_pmp_doc($this->pmp_story);

		$this->assertEquals($post_data['post_title'], $this->pmp_story['attributes']['title']);
		$this->assertEquals($post_data['post_content'], $this->pmp_story['attributes']['contentencoded']);
		$this->assertEquals($post_data['post_excerpt'], $this->pmp_story['attributes']['teaser']);
		$this->assertEquals($post_data['post_date'], date('Y-m-d H:i:s', strtotime($this->pmp_story['attributes']['published'])));
	}

	function test_pmp_get_post_meta_from_pmp_doc() {
		$post_meta = pmp_get_post_meta_from_pmp_doc($this->pmp_story);

		$this->assertEquals($post_meta['pmp_guid'], $this->pmp_story['attributes']['guid']);
		$this->assertEquals($post_meta['pmp_created'], $this->pmp_story['attributes']['created']);
		$this->assertEquals($post_meta['pmp_modified'], $this->pmp_story['attributes']['modified']);
		$this->assertEquals($post_meta['pmp_byline'], $this->pmp_story['attributes']['byline']);
		$this->assertEquals($post_meta['pmp_published'], $this->pmp_story['attributes']['published']);
		$this->assertEquals($post_meta['pmp_owner'], SDKWrapper::guid4href($this->pmp_story['links']['owner'][0]->href));
	}

	function test_pmp_filter_media_library() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	function test_pmp_get_my_guid() {
		$me = $this->sdk_wrapper->fetchUser('me');
		$this->assertEquals(pmp_get_my_guid(), $me->attributes->guid);
	}

	function test_pmp_update_my_guid_transient() {
		$this->markTestSkipped(
			'Functional test of `pmp_update_my_guid_transient` performed by `test_pmp_get_my_guid`');
	}

	function test_var_log() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}
}
