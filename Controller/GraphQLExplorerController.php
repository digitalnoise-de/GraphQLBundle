<?php

namespace Youshido\GraphQLBundle\Controller;

use DateTimeImmutable;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class GraphQLExplorerController extends AbstractController
{
    /**
     * @Route("/graphql/explorer")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function explorerAction()
    {
        $response = $this->render('@GraphQLBundle/Feature/explorer.html.twig', [
            'graphQLUrl'  => $this->generateUrl('youshido_graphql_graphql_default'),
            'tokenHeader' => 'access-token',
        ]);

        $date = new DateTimeImmutable('tomorrow', new \DateTimeZone('UTC'));
        $response->setExpires($date);
        $response->setPublic();

        return $response;
    }
}
