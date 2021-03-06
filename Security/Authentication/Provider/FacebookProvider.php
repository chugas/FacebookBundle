<?php

/*
 * This file is part of the BITFacebookBundle package.
 *
 * (c) bitgandtter <http://bitgandtter.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace BIT\FacebookBundle\Security\Authentication\Provider;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Provider\AuthenticationProviderInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use BIT\FacebookBundle\Security\User\UserManagerInterface;
use BIT\FacebookBundle\Security\Authentication\Token\FacebookUserToken;

class FacebookProvider implements AuthenticationProviderInterface
{
  protected $providerKey;
  protected $facebook;
  protected $session;
  protected $userProvider;
  protected $userChecker;
  protected $createIfNotExists;
  
  public function __construct( $providerKey, \BaseFacebook $facebook, Session $session,
      UserProviderInterface $userProvider = null, UserCheckerInterface $userChecker = null, $createIfNotExists = false )
  {
    $errorMessage = '$userChecker cannot be null, if $userProvider is not null.';
    if ( null !== $userProvider && null === $userChecker )
      throw new \InvalidArgumentException( $errorMessage);
    
    $errorMessage = 'The $userProvider must implement UserManagerInterface if $createIfNotExists is true.';
    if ( $createIfNotExists && !$userProvider instanceof UserManagerInterface )
      throw new \InvalidArgumentException( $errorMessage);
    
    $this->providerKey = $providerKey;
    $this->facebook = $facebook;
    $this->session = $session;
    $this->userProvider = $userProvider;
    $this->userChecker = $userChecker;
    $this->createIfNotExists = $createIfNotExists;
    
    // workaraound to use a previous login valid js access token setted in the session
    $sessionAccessToken = $this->session->get( "fb.accessToken" );
    $apiAccessToken = $this->facebook->getAccessToken( );
    $appAccessToken = $this->facebook->getAppId( ) . '|' . $this->facebook->getAppSecret( );
    
    if ( $sessionAccessToken !== $apiAccessToken && $apiAccessToken === $appAccessToken )
      $this->facebook->setAccessToken( $sessionAccessToken );
  }
  
  public function authenticate( TokenInterface $token )
  {
    if ( !$this->supports( $token ) )
      return null;
    
    $user = $token->getUser( );
    if ( $user instanceof UserInterface )
    {
      $this->userChecker->checkPostAuth( $user );
      
      $newToken = new FacebookUserToken( $this->providerKey, $user, $user->getRoles( ));
      $newToken->setAttributes( $token->getAttributes( ) );
      
      return $newToken;
    }
    
    try
    {
      if ( $uid = $this->facebook->getUser( ) )
      {
        $newToken = $this->createAuthenticatedToken( $uid );
        $newToken->setAttributes( $token->getAttributes( ) );
        
        return $newToken;
      }
    }
    catch ( AuthenticationException $failed )
    {
      throw $failed;
    }
    catch ( \Exception $failed )
    {
      throw new AuthenticationException( $failed->getMessage( ), ( int ) $failed->getCode( ), $failed);
    }
    
    throw new AuthenticationException( 'The Facebook user could not be retrieved from the session.');
  }
  
  public function supports( TokenInterface $token )
  {
    return $token instanceof FacebookUserToken && $this->providerKey === $token->getProviderKey( );
  }
  
  protected function createAuthenticatedToken( $uid )
  {
    if ( null === $this->userProvider )
      return new FacebookUserToken( $this->providerKey, $uid);
    
    try
    {
      $user = $this->userProvider->loadUserByUsername( $uid );
      $this->userChecker->checkPostAuth( $user );
    }
    catch ( UsernameNotFoundException $ex )
    {
      if ( !$this->createIfNotExists )
        throw $ex;
      
      $user = $this->userProvider->createUserFromUid( $uid );
    }
    
    if ( !$user instanceof UserInterface )
      throw new \RuntimeException( 'User provider did not return an implementation of user interface.');
    
    return new FacebookUserToken( $this->providerKey, $user, $user->getRoles( ));
  }
}
