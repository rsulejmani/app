<?php

class WallHooksHelperTest extends WikiaBaseTest {
	/**
	 * SUS-1553: Verify user can edit their own wall greeting and that they need 'walledit' permission to edit others' greetings
	 *
	 * @dataProvider wallGreetingRestrictionsDataProvider
	 * @param bool $userCanWallEdit
	 * @param bool $isUserWallOwner
	 */
	public function testWallGreetingRestrictions( bool $userCanWallEdit, bool $isUserWallOwner ) {
		$titleMock = $this->getTitleMock( NS_USER_WALL_MESSAGE_GREETING );

		$userMock = $this->getMock( User::class, [ 'isAllowed' ] );
		$userMock->expects( $this->once() )
			->method( 'isAllowed' )
			->with( 'walledit' )
			->willReturn( $userCanWallEdit );

		$wallMessageMock = $this->getMockBuilder( WallMessage::class )
			->disableOriginalConstructor()
			->setMethods( [ 'isWallOwner' ] )
			->getMock();
		if ( !$userCanWallEdit ) {
			$wallMessageMock->expects( $this->once() )
				->method( 'isWallOwner' )
				->with( $userMock )
				->willReturn( $isUserWallOwner );
		} else {
			$wallMessageMock->expects( $this->never() )
				->method( $this->anything() );
		}
		$this->mockClass( WallMessage::class, $wallMessageMock );

		$err = null;
		$res = WallHooksHelper::onGetUserPermissionsErrors( $titleMock, $userMock, 'edit', $err );

		$allow = $userCanWallEdit || $isUserWallOwner;
		if ( !$allow ) {
			$this->assertFalse( $res );
			$this->assertContains( 'badaccess-group0', $err );
		} else {
			$this->assertTrue( $res );
			$this->assertEmpty( $err );
		}
	}

	/**
	 * SUS-1553: Test that user cannot create Wall or Thread manually, and can't delete Message Wall
	 *
	 * @dataProvider wallNamespaceRestrictionsDataProvider
	 * @param int $ns
	 * @param string $action
	 * @param bool $isValidTransaction
	 * @param bool $permit
	 */
	public function testWallNamespaceRestrictions( int $ns, string $action, bool $isValidTransaction, bool $permit ) {
		$titleMock = $this->getTitleMock( $ns );
		$userMock = new User;
		$this->mockGlobalVariable( 'wgIsValidWallTransaction', $isValidTransaction );
		$this->mockGlobalVariable( 'wgCommandLineMode', $isValidTransaction );

		$err = null;
		$res = WallHooksHelper::onGetUserPermissionsErrors( $titleMock, $userMock, $action, $err);

		if ( !$permit ) {
			$this->assertFalse( $res );
			$this->assertContains( 'badtitle', $err );
		} else {
			$this->assertTrue( $res );
			$this->assertEmpty( $err );
		}
	}

	private function getTitleMock( int $ns ): PHPUnit_Framework_MockObject_MockObject {
		$titleMock = $this->getMock( Title::class, [ 'getNamespace' ] );
		$titleMock->expects( $this->once() )
			->method( 'getNamespace' )
			->willReturn( $ns );

		return $titleMock;
	}

	public function wallGreetingRestrictionsDataProvider(): array {
		return [
			'user with no permissions' => [ false, false ],
			'user with walledit permission' => [ true, false ],
			'wall owner user' => [ false, true ],
			'wall owner user with walledit permission' => [ true, true ],
		];
	}

	public function wallNamespaceRestrictionsDataProvider(): array {
		return [
			'robot creating Message Wall' => [ NS_USER_WALL, 'create', true, true ],
			'user trying to create Message Wall' => [ NS_USER_WALL, 'create', false, false ],
			'user trying to edit Message Wall' => [ NS_USER_WALL, 'create', false, false ],
			'user trying to delete Message Wall' => [ NS_USER_WALL, 'delete', false, false ],
			'user trying to create thread manually' =>  [ NS_USER_WALL_MESSAGE, 'create', false, false ],
			'user posting thread via Nirvana' => [ NS_USER_WALL_MESSAGE, 'create', true, true ],
			'user editing thread manually' => [ NS_USER_WALL_MESSAGE, 'edit', false, true ],
			'user editing thread via Nirvana' => [ NS_USER_WALL_MESSAGE, 'edit', true, true ],
		];
	}
}
