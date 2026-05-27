<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    // Symfony is mounted at /cms-admin/ via nginx rewrite; the base path is
    // detected from SCRIPT_NAME and stripped before route matching, so routes
    // are defined as if at root.
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        return $this->render('security/login.html.twig', [
            'error' => $auth->getLastAuthenticationError(),
            'last_username' => $auth->getLastUsername(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Handled by the security firewall.');
    }
}
