<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Security\Authentication\Token;

use Contao\BackendUser;
use Contao\FrontendUser;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Http\FirewallMapInterface;

class TokenChecker
{
    private const FRONTEND_FIREWALL = 'contao_frontend';
    private const BACKEND_FIREWALL = 'contao_backend';

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var FirewallMapInterface
     */
    private $firewallMap;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * @var AuthenticationTrustResolverInterface
     */
    private $trustResolver;

    /**
     * @var VoterInterface
     */
    private $roleVoter;

    /**
     * @var string
     */
    private $previewScript;

    /**
     * @internal Do not inherit from this class; decorate the "contao.security.token_checker" service instead
     */
    public function __construct(RequestStack $requestStack, FirewallMapInterface $firewallMap, TokenStorageInterface $tokenStorage, SessionInterface $session, AuthenticationTrustResolverInterface $trustResolver, VoterInterface $roleVoter, string $previewScript = '')
    {
        $this->requestStack = $requestStack;
        $this->firewallMap = $firewallMap;
        $this->tokenStorage = $tokenStorage;
        $this->session = $session;
        $this->trustResolver = $trustResolver;
        $this->roleVoter = $roleVoter;
        $this->previewScript = $previewScript;
    }

    /**
     * Checks if a front end user is authenticated.
     */
    public function hasFrontendUser(): bool
    {
        $token = $this->getToken(self::FRONTEND_FIREWALL);

        return null !== $token && VoterInterface::ACCESS_GRANTED === $this->roleVoter->vote($token, null, ['ROLE_MEMBER']);
    }

    /**
     * Checks if a back end user is authenticated.
     */
    public function hasBackendUser(): bool
    {
        $token = $this->getToken(self::BACKEND_FIREWALL);

        return null !== $token && VoterInterface::ACCESS_GRANTED === $this->roleVoter->vote($token, null, ['ROLE_USER']);
    }

    /**
     * Gets the front end username from the session.
     */
    public function getFrontendUsername(): ?string
    {
        $token = $this->getToken(self::FRONTEND_FIREWALL);

        if (null === $token || !$token->getUser() instanceof FrontendUser) {
            return null;
        }

        return $token->getUser()->getUsername();
    }

    /**
     * Gets the back end username from the session.
     */
    public function getBackendUsername(): ?string
    {
        $token = $this->getToken(self::BACKEND_FIREWALL);

        if (null === $token || !$token->getUser() instanceof BackendUser) {
            return null;
        }

        return $token->getUser()->getUsername();
    }

    /**
     * Tells whether the front end preview can show unpublished fragments.
     */
    public function isPreviewMode(): bool
    {
        $request = $this->requestStack->getMasterRequest();

        if (null === $request || $request->getScriptName() !== $this->previewScript) {
            return false;
        }

        $token = $this->getToken(self::FRONTEND_FIREWALL);

        return $token instanceof FrontendPreviewToken && $token->showUnpublished();
    }

    private function getToken(string $context): ?TokenInterface
    {
        $token = $this->getTokenFromStorage($context);

        if (null === $token) {
            $token = $this->getTokenFromSession('_security_'.$context);
        }

        if (!$token instanceof TokenInterface || !$token->isAuthenticated()) {
            return null;
        }

        if ($this->trustResolver->isAnonymous($token)) {
            return null;
        }

        return $token;
    }

    private function getTokenFromStorage(string $context): ?TokenInterface
    {
        $request = $this->requestStack->getMasterRequest();

        if (!$this->firewallMap instanceof FirewallMap || null === $request) {
            return null;
        }

        $config = $this->firewallMap->getFirewallConfig($request);

        if (!$config instanceof FirewallConfig || $config->getContext() !== $context) {
            return null;
        }

        return $this->tokenStorage->getToken();
    }

    private function getTokenFromSession(string $sessionKey): ?TokenInterface
    {
        if (!$this->session->isStarted() || !$this->session->has($sessionKey)) {
            return null;
        }

        $token = unserialize($this->session->get($sessionKey), ['allowed_classes' => true]);

        if (!$token instanceof TokenInterface) {
            return null;
        }

        return $token;
    }
}
