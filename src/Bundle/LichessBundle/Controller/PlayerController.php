<?php

namespace Bundle\LichessBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Bundle\LichessBundle\Document\Player;
use Bundle\LichessBundle\Document\Game;
use Bundle\LichessBundle\Chess\FinisherException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use RuntimeException;
use Lichess\OpeningBundle\Config\GameConfig;

class PlayerController extends Controller
{
    public function rematchAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);
        $game = $this->get('lichess.rematcher')->rematch($player);
        if ($game) {
            $this->get('lila')->rematchAccept($player, $game);
        } else {
            $this->get('lila')->rematchOffer($player->getGame());
        }
        $this->flush();

        return new Response('ok');
    }

    public function forceResignAction($id)
    {
        $this->get('lichess.finisher')->forceResign($this->get('lichess.provider')->findPlayer($id));
        $this->flush();

        return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function offerDrawAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);

        if ($message = $this->get('lichess.drawer')->offer($player)) {
            $this->flush();
            $this->get('lila')->draw($player, $message['message']);
        }

        return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function declineDrawOfferAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);

        if ($message = $this->get('lichess.drawer')->decline($player)) {
            $this->flush();
            $this->get('lila')->draw($player, $message['message']);
        }

        return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function cancelDrawOfferAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);

        if ($message = $this->get('lichess.drawer')->cancel($player)) {
            $this->flush();
            $this->get('lila')->draw($player, $message['message']);
        }

        return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function acceptDrawOfferAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);

        if ($message = $this->get('lichess.drawer')->accept($player)) {
            $this->flush();
            $this->get('lila')->drawAccept($player, $message['message']);
        }

        return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function claimDrawAction($id)
    {
        $this->get('lichess.finisher')->claimDraw($this->get('lichess.provider')->findPlayer($id));
        $this->flush();

        return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function moveAction($id)
    {
        throw new \Exception("lila");
    }

    public function showAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);
        $game = $player->getGame();

        if ($player->getIsAi()) {
            throw new NotFoundHttpException('Can not show AI player');
        } elseif ($player->getUser() && $player->getUser() != $this->getAuthenticatedUser()) {
            // protect game against private url sharing
            return new RedirectResponse($this->generateUrl('lichess_game', array('id' => $game->getId(), 'color' => $player->getColor())));
        }
        $this->get('lila')->alive($player);

        if(!$game->getIsStarted()) {
            if ($this->get('lila')->getActivity($player->getOpponent()) > 0) {
                $this->get('lichess.joiner')->join($player);
                $this->flush();
            } else {
                return $this->render('LichessBundle:Player:waitOpponent.html.twig', array('player' => $player));
            }
        }

        return $this->render('LichessBundle:Player:show.html.twig', array(
            'player'              => $player,
            'room'                => $this->get('lichess.repository.room')->findOneByGame($game),
            'opponentActivity'    => $this->get('lila')->getActivity($player->getOpponent()),
            'checkSquareKey'      => $game->getCheckSquareKey(),
            'possibleMoves'       => ($player->isMyTurn() && $game->getIsPlayable()) ? $this->get('lila')->possibleMoves($player) : null
        ));
    }

    /**
     * Add a message to the chat room
     */
    public function sayAction($id)
    {
        $message = trim($this->get('request')->get('message'));
        $player = $this->get('lichess.provider')->findPlayer($id);
        $messageData = $this->get('lichess.messenger')->addPlayerMessage($player, $message);
        if ($messageData) {
            $this->flush();
            $this->get('lila')->talk($player->getGame(), $messageData);
        }

        return new Response('ok');
    }

    public function waitFriendAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);
        if($player->getGame()->getIsStarted()) {
            return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
        }
        $this->get('lila')->alive($player);

        $config = new GameConfig();
        $config->fromArray($this->get('session')->get('lichess.game_config.friend', array()));
        return $this->render('LichessBundle:Player:waitFriend.html.twig', array(
            'player' => $player,
            'config' => $config->createView()
        ));
    }

    public function cancelFriendAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);
        $this->get('doctrine.odm.mongodb.document_manager')->remove($player->getGame());
        $this->flush();

        return new RedirectResponse($this->generateUrl('lichess_homepage'));
    }

    public function resignAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);
        try {
            $this->get('lichess.finisher')->resign($this->get('lichess.provider')->findPlayer($id));
            $this->flush();
            $this->get('lila')->end($player->getGame());
        } catch (FinisherException $e) {}

            return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function abortAction($id)
    {
        $player = $this->get('lichess.provider')->findPlayer($id);
        try {
            $this->get('lichess.finisher')->abort($player);
            $this->flush();
            $this->get('lila')->end($player->getGame());
        } catch (FinisherException $e) {}

            return new RedirectResponse($this->generateUrl('lichess_player', array('id' => $id)));
    }

    public function tableAction($id, $color, $playerFullId)
    {
        if($playerFullId) {
            $player = $this->get('lichess.provider')->findPlayer($playerFullId);
            $template = $player->getGame()->getIsPlayable() ? 'table' : 'tableEnd';
        }
        else {
            $player = $this->get('lichess.provider')->findPublicPlayer($id, $color);
            $template = 'watchTable';
        }
        return $this->render('LichessBundle:Game:'.$template.'.html.twig', array(
            'player'           => $player,
            'opponentActivity' => $this->get('lila')->getActivity($player->getOpponent())
        ));
    }

    public function opponentAction($id, $color, $playerFullId)
    {
        if($playerFullId) {
            $player = $this->get('lichess.provider')->findPlayer($playerFullId);
        } else {
            $player = $this->get('lichess.provider')->findPublicPlayer($id, $color);
        }
        $opponent = $player->getOpponent();

        return $this->opponentPlayerAction($opponent, $playerFullId);
    }

    public function opponentPlayerAction(Player $opponent, $playerFullId)
    {
        if($playerFullId) {
            $template = 'opponent';
        } else {
            $template = 'watchOpponent';
        }
        $opponentActivity = $playerFullId ? $this->get('lila')->getActivity($opponent) : 2;

        return $this->render('LichessBundle:Player:'.$template.'.html.twig', array(
            'opponent'         => $opponent,
            'opponentActivity' => $opponentActivity,
            'game'             => $opponent->getGame(),
            'playerFullId'     => $playerFullId
        ));
    }

    protected function renderJson($data)
    {
        return new Response(json_encode($data), 200, array('Content-Type' => 'application/json'));
    }

    protected function flush($safe = true)
    {
        return $this->get('doctrine.odm.mongodb.document_manager')->flush(array('safe' => $safe));
    }

    protected function getAuthenticatedUser()
    {
        return $this->get('security.context')->getToken()->getUser();
    }
}
