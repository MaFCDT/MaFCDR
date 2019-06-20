<?php

namespace BM2\SiteBundle\Controller;

use BM2\SiteBundle\Entity\Character;
use BM2\SiteBundle\Entity\GameRequest;
use BM2\SiteBundle\Entity\House;

use BM2\SiteBundle\Form\SoldierFoodType;

use BM2\SiteBundle\Service\Appstate;
use BM2\SiteBundle\Service\History;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;


/**
 * @Route("/gamerequest")
 */
class GameRequestController extends Controller {

	private $house;

	private function security(Character $char, GameRequest $id) {
		/* Most other places in the game have a single dispatcher call to do security. Unfortunately, for GameRequests, it's not that easy, as this file handles *ALL* processing of the request itself.
		That means, we need a way to check whether or not a given user has rights to do things, when the things in questions could vary every time this controller is called. 
		Yes, I realize this is a massive bastardization of how Symfony says Symfony is supposed to handle things, mainly that they say this should be in a Service as it's all back-end stuff, but if it works, it works.
		Maybe in the future, when I'm looking to refine things, we can move it around then. Really, all that'd change is these being moved to the service and returning a true or false--personally I like all the logic being in one place though.*/
		$result;
		switch ($id->getType()) {
			case 'soldier.food':
				if ($id->getToSettlement()->getOwner() != $char) {
					$result = false;
				} else {
					$result = true;
				}
				break;
			case 'house.join':
				if ($char->getHeadOfHouse() != $id->getToHouse()) {
					$result = false;
				} else {
					$result = true;
				}
				break;
		}
		return $result;
	}

	/**
	  * @Route("/{id}/approve", name="bm2_gamerequest_approve", requirements={"id"="\d+"})
	  */
	
	public function approveAction(GameRequest $id) {
		$character = $this->get('appstate')->getCharacter();
		if (! $character instanceof Character) {
			return $this->redirectToRoute($character);
		}
		$em = $this->getDoctrine()->getManager();
		# Are we allowed to act on this GR? True = yes. False = no.
		$allowed = $this->security($character, $id);
		# Do try to keep this switch and the denyAction switch in the order of most expected request. It'll save processing time.
		switch($id->getType()) {
			case 'soldier.food':
				if ($allowed) {
					$settlement = $id->getToSettlement();
					$character = $id->getFromCharacter();
					$character->setSoldierFood($settlement);
					$this->get('history')->logEvent(
						$settlement,
						'event.military.supplier.food.start',
						array('%link-character%'=>$id->getFromCharacter()->getId()),
						History::LOW, true
					);
					$this->get('history')->logEvent(
						$id->getFromCharacter(),
						'event.military.supplied.food.start',
						array('%link-character%'=>$settlement->getOwner()->getId(), '%link-settlement%'=>$settlement->getId()),
						History::LOW, true
					);
					$id->setAccepted(true);
					$em->flush();
					$this->addFlash('notice', $this->get('translator')->trans('military.settlement.food.supplied', array('%character%'=>$id->getFromCharacter()->getName(), '%settlement%'=>$id->getToSettlement()->getName()), 'actions'));
					return $this->redirectToRoute('bm2_gamerequest_manage');
				} else {
					throw new AccessDeniedHttpException('unavailable.notlord');
				}
				break;
			case 'house.join':
				if ($allowed) {
					$house = $id->getToHouse();
					$character = $id->getFromCharacter();
					$character->setHouse($house);
					$character->setHouseJoinDate(new \DateTime("now"));
					$this->get('history')->openLog($house, $character);
					$this->get('history')->logEvent(
						$house,
						'event.house.newmember',
						array('%link-character%'=>$id->getFromCharacter()->getId()),
						History::MEDIUM, true
					);
					$this->get('history')->logEvent(
						$id->getFromCharacter(),
						'event.character.joinhouse.approved',
						array('%link-house%'=>$house->getId()),
						History::ULTRA, true
					);
					$em->remove($id);
					$em->flush();
					$this->addFlash('notice', $this->get('translator')->trans('house.manage.applicant.approved', array('%character%'=>$id->getFromCharacter()->getName()), 'politics'));
					return $this->redirectToRoute('bm2_house_applicants', array('id'=>$house->getId()));
				} else {
					throw new AccessDeniedHttpException('unavailable.nothead');
				}
				break;
		}
		
		return new Response();
	}

	/**
	  * @Route("/{id}/deny", name="bm2_gamerequest_deny", requirements={"id"="\d+"})
	  */
	
	public function denyAction(GameRequest $id) {
		$character = $this->get('appstate')->getCharacter();
		if (! $character instanceof Character) {
			return $this->redirectToRoute($character);
		}
		$em = $this->getDoctrine()->getManager();
		# Are we allowed to act on this GR? True = yes. False = no.
		$allowed = $this->security($character, $id);
		switch($id->getType()) {
			case 'soldier.food':
				if ($allowed) {
					$settlement = $id->getToSettlement();
					# Create event notice for denied character.
					$this->get('history')->logEvent(
						$id->getFromCharacter(),
						'event.military.supplied.food.rejected',
						array('%link-settlement%'=>$settlement->getId()),
						History::LOW, true
					);
					# Set accepted to false so we can hang on to this to prevent spamming. These get removed after a week, hence the new expiration date.
					$id->setAccepted(FALSE);
					$timeout = new \DateTime("now");
					$id->setExpires($timeout->add(new \DateInterval("P7D")));
					$em->flush();
					$this->addFlash('notice', $this->get('translator')->trans('military.settlement.food.rejected', array('%character%'=>$id->getFromCharacter()->getName(), '%settlement%'=>$id->getToSettlement()->getName()), 'actions'));
					return $this->redirectToRoute('bm2_gamerequest_manage');
				} else {
					throw new AccessDeniedHttpException('unavailable.notlord');
				}
				break;
			case 'house.join':
				if ($allowed) {
					$house = $id->getToHouse();
					$this->get('history')->logEvent(
						$id->getFromCharacter(),
						'event.character.joinhouse.denied',
						array('%link-house%'=>$house->getId()),
						History::HIGH, true
					);
					$em->remove($id);
					$em->flush();
					$this->addFlash('notice', $this->get('translator')->trans('house.manage.applicant.denied', array('%character%'=>$id->getFromCharacter()->getName()), 'politics'));
					return $this->redirectToRoute('bm2_house_applicants', array('house'=>$house->getId()));
				} else {
					throw new AccessDeniedHttpException('unavailable.nothead');
				}
				break;
		}
		
		return new Response();
	}

	/**
	  * @Route("/manage", name="bm2_gamerequest_manage")
	  * @Template
	  */

	public function manageAction() {
		$character = $this->get('dispatcher')->gateway('personalRequestsManageTest');
		if (! $character instanceof Character) {
			return $this->redirectToRoute($character);
		}
		$em = $this->getDoctrine()->getManager();
		# TODO: Rework this to use dispatcher.
		$requests = $this->get('game_request_manager')->findAllManageableRequests($character);

		return array(
			'gamerequests' => $requests
		);
	}

	/**
	  * @Route("/soldierfood", name="bm2_gamerequest_soldierfood")
	  * @Template
	  */

	public function soldierfoodAction(Request $request) {
		# Get player character from security and check their access.		
		$character = $this->get('dispatcher')->gateway('personalRequestSoldierFoodTest');
		if (! $character instanceof Character) {
			return $this->redirectToRoute($character);
		}
		# Get all character realms.
		$myRealms = $character->findRealms();
		$topRealms = new ArrayCollection;
		# Sort through all realms and compile collection of top realms.
		foreach ($myRealms as $realm) {
			if (!$topRealms->contains($realm->findUltimate())) {
				$topRealms->add($realm->findUltimate());
			}
		}
		# Establish realm IDs array, go through all top realms to find all inferior realms (including self, hence the 'true') and add the realm IDs to $realms.
		$realms = array();
		foreach ($topRealms as $topRealm) {
			foreach ($topRealm->findAllInferiors(true) as $indRealm) {
				$realms[] = $indRealm->getId();
			}
		}

		$form = $this->createForm(new SoldierFoodType($realms));
		$form->handleRequest($request);
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			# newRequestFromCharactertoSettlement ($type, $expires = null, $numberValue = null, $stringValue = null, $subject = null, $text = null, Character $fromChar = null, Settlement $toSettlement = null)
			$this->get('game_request_manager')->newRequestFromCharacterToSettlement('soldier.food', $data['expires'], $data['limit'], null, $data['subject'], $data['text'], $character, $data['target']);
			$this->addFlash('notice', $this->get('translator')->trans('request.soldierfood.sent', array('%settlement%'=>$data['target']->getName()), 'actions'));
			return $this->redirectToRoute('bm2_actions');
		}
		return array(
			'form' => $form->createView(),
			'size' => $character->getEntourage()->count()+$character->getSoldiers()->count()
		);
	}


}
