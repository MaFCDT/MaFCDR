<?php

namespace BM2\SiteBundle\Service;

use BM2\SiteBundle\Entity\Building;
use BM2\SiteBundle\Entity\GeoFeature;
use BM2\SiteBundle\Entity\GeoResource;
use BM2\SiteBundle\Entity\ResourceType;
use BM2\SiteBundle\Entity\Road;
use BM2\SiteBundle\Entity\Settlement;
use BM2\SiteBundle\Entity\Character;
use Doctrine\ORM\EntityManager;
use Monolog\Logger;

/*
	FIXME:
	This should somehow be split into smaller work packages also to support multi-threading

	maybe with the commented-out changes in GeoResource.orm.xml we can multi-thread pre-calculate the resource
	demand and production values and store them in the DB. This would remove a lot from the main turn calculations
	(run the recalc before async, wait for completion, to make sure everything is updated)

	other things that can run in parallel are soldier training, supply production, building construction, etc.

	==> this requires a new structure in the game runner
*/

class Economy {

	private $em;
	private $geo;
	private $history;
	private $logger;

	private $hours_per_day = 10;
	private $timer=array("updateproduction"=>0, "demand"=>0, "foodsupply"=>0, "resourceproduction2"=>0, "tradebalance2"=>0);

	private $resources = null;

	public function getTimer() {
		return $this->timer;
	}


	public function __construct(EntityManager $em, Geography $geo, History $history, Logger $logger) {
		$this->em = $em;
		$this->geo = $geo;
		$this->history = $history;
		$this->logger = $logger;
	}

	public function getResources() {
		if (!$this->resources) {
			$this->resources = $this->em->getRepository('BM2SiteBundle:ResourceType')->findAll();
		}
		return $this->resources;
	}

	public function fixTrades(Settlement $settlement, ResourceType $resource, $production, $tradebalance) {
		$shortage = abs($tradebalance) - $production;
		$reduction = 0;
		if ($shortage > 0) {
			$this->logger->info('insufficient '.$resource->getName().' in '.$settlement->getName()." ($shortage short, tradebalance $tradebalance)");
			$reduction += $this->reduceTrade($settlement, $resource, $shortage);
		}
		return $reduction;
	}

	public function reduceTrade(Settlement $settlement, ResourceType $resource, $shortage) {
		$reduction = 0;
		$outgoing = $settlement->getTradesOutbound()->filter(
			function($entry) use ($resource) {
				return ($entry->getResourceType()->getId()==$resource->getId()); // for some reason the direct comparison (without ->getId()) doesn't work?
			}
		);

		$current = $outgoing->first();
		while ($shortage >0 && $current) {
			$reduce = false;
			// a little overcorrection so we have space to recover, this is especially important for food
			if ($shortage < $current->getAmount()/11) {
				$reduce = round($current->getAmount()/10);
			} else if ($shortage < $current->getAmount()/5) {
				$reduce = round($current->getAmount()/4);
			} else if ($shortage < $current->getAmount()/2.5) {
				$reduce = round($current->getAmount()/2);
			} else if ($shortage < $current->getAmount()*0.8 && $current->getAmount()>=20) { // don't reduce from 4 to 1 or such nonsense
				$reduce = round($current->getAmount()*0.75);
			}
			if ($reduce) {
				$new = $current->getAmount() - $reduce;
				$this->logger->info('reducing trade of '.$current->getAmount()." by $reduce to $new");
				$this->history->logEvent(
					$settlement,
					'event.settlement.tradereduce',
					array('%amount%'=>$current->getAmount(), '%newamount%'=>$new, '%resource%'=>$resource->getName(), '%link-settlement%'=>$current->getDestination()->getId()),
					History::MEDIUM, false, 20
				);
				$this->history->logEvent(
					$current->getDestination(),
					'event.settlement.tradereduced',
					array('%amount%'=>$current->getAmount(), '%newamount%'=>$new, '%resource%'=>$resource->getName(), '%link-settlement%'=>$settlement->getId()),
					History::MEDIUM, false, 15
				);

				$shortage -= $reduce;
				$reduction += $reduce;
				$current->setAmount($new);
			} else {
				$this->logger->info('cancelling trade of '.$current->getAmount());
				$this->history->logEvent(
					$settlement,
					'event.settlement.tradefail',
					array('%amount%'=>$current->getAmount(), '%resource%'=>$resource->getName(), '%link-settlement%'=>$current->getDestination()->getId()),
					History::MEDIUM, false, 30
				);
				$this->history->logEvent(
					$current->getDestination(),
					'event.settlement.tradestop',
					array('%amount%'=>$current->getAmount(), '%resource%'=>$resource->getName(), '%link-settlement%'=>$settlement->getId()),
					History::MEDIUM, false, 20
				);

				$shortage -= $current->getAmount();
				$reduction += $current->getAmount();
				$this->em->remove($current);
				$settlement->removeTradesOutbound($current);
				$current->getDestination()->removeTradesInbound($current);
			}
			$current = $outgoing->next();
		}
		return $reduction;
	}

	public function checkWorkforce(Settlement $settlement) {
		$workforce = $settlement->getAvailableWorkforce();
		if ($workforce < 0) {
			// this can happen if the settlement has become very small and employees > population
			$chance = 60;
			foreach ($settlement->getBuildings() as $building) {
				if ($building->isActive()) {
					if (rand(0,99) < $chance) {
						$building->setActive(false);
						$building->setCondition(-rand(10,200));
						$chance -= 20;
					} else {
						$chance += 20;
					}
				} else {
					$building->setWorkers(0);
					$building->setCondition($building->getCondition()-rand(10,100));
				}
			}
			foreach ($settlement->getGeoData()->getRoads() as $road) {
				$road->setWorkers(0);
			}
			foreach ($settlement->getGeoData()->getFeatures() as $feature) {
				$feature->setWorkers(0);
			}
			if ($workforce < 0) {
				// still? ok, abandon all buildings now, it's the only thing that can cause this
				foreach ($settlement->getBuildings() as $building) {
					if ($building->isActive() && $building->getType()->getDefenses() < 10) {
						$building->setActive(false);
						$building->setCondition(-rand(10,200));
					}
				}
			}
                        $workforce = $settlement->getAvailableWorkforce();
			if ($workforce < 0) {
				// Just how many people are we short!? All we have left is defenses... 
				foreach ($settlement->getBuildings() as $building) {
					if ($building->isActive()) {
						$building->setActive(false);
						$building->setCondition(-rand(10,200));
					}
				}
			} 
			$workforce = $settlement->getAvailableWorkforce();
			if ($workforce < 0) {
				// This should be utterly impossible to ever happen (but it does, occasionally), so log if it does because that means something is really wrong
				$this->logger->error('workforce < 0 for settlement '.$settlement->getId());
			}
			$this->history->logEvent(
				$settlement,
				'event.settlement.crash',
				array(),
				History::HIGH, false, 60
			);
		}
	}


	public function checkSpecialConditions(Settlement $settlement, $building_name) {
		// special conditions - these are hardcoded because they can be complex
		switch (strtolower($building_name)) {
			case 'mine':	// only in hills and mountains with metal
				if ($settlement->getGeoData()->getHills() == false && $settlement->getGeoData()->getBiome()->getName() != 'rock') {
					return false;
				}
				$metal = $this->em->getRepository('BM2SiteBundle:ResourceType')->findOneByName("metal");
				$my_metal = $settlement->findResource($metal);
				if ($my_metal == null || $my_metal->getAmount()<=0) {
					return false;
				}
				break;
			case 'stables':	// only in grass- or scrublands
				$geo = $settlement->getGeoData()->getBiome()->getName();
				if (!in_array($geo, array('grass', 'thin grass', 'scrub', 'thin scrub'))) {
					return false;
				}
				break;
			case 'royal mews':	// only in grasslands
				$geo = $settlement->getGeoData()->getBiome()->getName();
				if (!in_array($geo, array('grass', 'thin grass'))) {
					return false;
				}
				break;
			case 'fishery':	// only at ocean or lake
				if ($settlement->getGeoData()->getCoast() == false && $settlement->getGeoData()->getLake() == false ) {
					return false;
				}
				break;
			case 'lumber yard':	// only in forests
				$geo = $settlement->getGeoData()->getBiome()->getName();
				if (!in_array($geo, array('forest', 'dense forest'))) {
					return false;
				}
				break;
			case 'irrigation ditches': // only near rivers, not in mountains or marshes
				if ($settlement->getGeoData()->getRiver() == false) {
					return false;
				}
				$geo = $settlement->getGeoData()->getBiome()->getName();
				if (in_array($geo, array('rock', 'marsh'))) {
					return false;
				}
				break;
			case 'fortress': // not in marshes
			case 'citadel': // not in marshes
				$geo = $settlement->getGeoData()->getBiome()->getName();
				if ($geo == 'marsh') {
					return false;
				}
				break;
			case 'archery range': // not in dense forest or mountains
				$geo = $settlement->getGeoData()->getBiome()->getName();
				if (in_array($geo, array('rock', 'dense forest'))) {
					return false;
				}
				break;
			case 'local seat': // only if lord is ruler of realm
			case 'regional seat': // only if lord is ruler of realm
			case 'royal seat': // only if lord is ruler of realm
			case 'imperial seat': // only if lord is ruler of realm
				if (!$settlement->getCapitalOf()) {
					return false;
				}
				if (!$settlement->getRealm()) {
					return false;
				}
				# Now that we know we have a realm and a capital, are we the ultimate realm?
				# We need all this because in_array can't accept an object as the second variable, only arrays. Arrays can however be the first variable submitted to it.
				$matchedrealms = false;
				if ($settlement->getRealm()->isUltimate()) {
					# If we are, do we have multiple capitals here?
					if (!is_array($settlement->getCapitalOf()->toArray())) {
						# If we don't, is that capital for this realm?
						if ($settlement->getCapitalOf() == $settlement->getRealm()) {
							$matchedrealms = true;
						}
					# If we have multiple capitals here, does our realm have one here?
					} else if (in_array($settlement->getRealm(), $settlement->getCapitalOf()->toArray())) {
						$matchedrealms = true;
					}
				} else {
					# Since we aren't the ultimate realm, we need to get all the ids for the superior realms (and ourselves!)
					foreach ($settlement->getRealm()->findAllSuperiors(true) as $realm) {
						$realmids[] = $realm->getId();
					}
					# Now, if we have mutliple capitals here, does the id of any of them match one of our superior realms or our own?
					if (is_array($settlement->getCapitalOf()->toArray())) {
						foreach ($settlement->getCapitalOf() as $capital) {
							if (in_array($capital->getId(), $realmids)) {
								$matchedrealms = true;
							}
						}
					} else {
						# If we only have one capital here, is it of one of our superior realms or our own realm?
						if (in_array($settlement->getCapitalOf()->getId(), $realmids)) {
							$matchedrealms = true;
						}
					}
				}
				# Finally, if we didn't match anywhere, we just return false, since we don't meet the conditions.
				if (!$matchedrealms) {
					return false;
				}
				/* If we want to restrict this to rulers, we can enable the below code.
				if (is_array($settlement->getRealm()->findRulers())) {
					if (!in_array($settlement->getOwner(), $settlement->getRealm()->findRulers())) {
						return false;
					}
				} else if ($settlement->getRealm()->findRulers() != $settlement->getOwner()) {
					return false;
				}
				*/
				break;
			case 'dockyard': // only at ocean
				/* TODO: We need a better way to do this before allowing these to be built.
				if ($settlement->getGeoData()->getCoast() == false) {
					return false;
				}
				*/
				return false;
				break;
			case 'filled moat': // only at a region that has water
				if ($settlement->getGeoData()->getCoast() == false && $settlement->getGeoData()->getLake() == false && $settlement->getGeoData()->getRiver() == false) {
					return false;
				}
				break;
			case 'quarry': //only at hills, scrublands, or mountains
				/* TODO: I don't think I want these implemented quite yet.
				if ($settlement->getGeoData()->getHills() == false && $settlement->getGeoData()->getBiome()->getName() != 'rock' && $settlement->getGeoData()->getBiome()->getName() != 'scrublands' && $settlement->getGeoData()->getBiome()->getName() != 'thin scrublands') {
					return false;
				} 
				*/
				return false;
				break;
		}
		return true;
	}


	public function updateSupplyAndStorage(Settlement $settlement, ResourceType $resource, $demand, $available) {
		$georesource = $settlement->findResource($resource);

		if (!$georesource) {
			$georesource = new GeoResource;
			$georesource->setAmount(0);
			$georesource->setStorage(0);
			$georesource->setType($resource);
			$georesource->setSettlement($settlement);
			$settlement->addResource($georesource);
			$this->em->persist($georesource);
		}

		// storage decays - fixed 2% per day
		$georesource->setStorage(floor($georesource->getStorage()*0.98));

		$use_storage = false;
		if ($resource->getName()!='money') {
			// all resources but money can be stored
			if ($available < $demand) {
				// shortage - use up to 20% (round up) of our storage to satisfy the shortage
 				$use = min($demand - $available, ceil($georesource->getStorage()*0.20));
				$georesource->setStorage($georesource->getStorage()-$use);
				$available += $use;
				$use_storage = true;
			}
			if ($available > $demand || ($available > $demand*0.9 && $resource->getName()=='food')) {
				// we have more than we need, we can put some into storage
				// for food, we always put a little aside, as long as we are not starving
				$max_storage = max(max(ceil($settlement->getPopulation()/10), $demand), $this->ResourceProduction($settlement, $resource, true)) * 10;
				if ($resource->getName()=='food') {
					// food we collect and store more efficiently, because the auto-adaptation anyway leads to small surpluses only
					$surplus = round(($available - ($demand*0.9))*0.75);
					$max_storage *= 2;
				} else {
					$surplus = round(($available - $demand)*0.5);
				}
				$georesource->setStorage(min($max_storage, $georesource->getStorage() + $surplus));
			}
		}

		if ($demand > 0) {
			$supply = $available/$demand;
		} else {
			$supply = 1.0;
		}
		$georesource->setSupply($supply);

		// calculate production speed modifier
		if ($demand>0 && $demand > $available) {
			// allow for some leeway so very low values don't crush us:
			$base_supply = sqrt($settlement->getPopulation())/10;
			$mod = min(1.0, ($available+$base_supply)/$demand);
			$mod = sqrt(max(0.1, min(1.0, $mod)));
		} else {
			$mod = 1.0;
		}
		$georesource->setMod($mod);

		if ($use_storage) {
			// if we used storage, we don't actually have more available than our demand
			return min($available, $demand);
		} else {
			return $available;
		}
	}

	public function getSupply(Settlement $settlement) {
		// TODO: now that we store mod in georesource - do we even need this anymore?
		$supply_data = array();
		foreach ($this->getResources() as $resource) {
			$supply_data[$resource->getId()] = 0;
		}
		foreach ($settlement->getResources() as $resource) {
			$supply_data[$resource->getType()->getId()] = $resource->getMod();
		}
		return $supply_data;
	}


	public function FoodSupply(Settlement $settlement, $shortage) {
		$real_shortage = $shortage;
		// this is mostly arrived at via visual inspection of graphs and some number tests, it works out as follows:
		// - population growth/shrinkage depends on how well-fed the population is
		// - it is a near-normal-distribution, (the $growth= part) so it can't explode or implode too suddenly
		// - starvation has a faster effect than oversupply, due to the range of the function (0 to inf)
		// - that is why $shortage is used instead of the inverse, supply=(FoodProduction-Population)/Population
		// - this function will converge on the stable norm value for a region in less than 50 iterations for all but the most extreme start values
		if ($shortage>0) {
			// we have a shortage - figure in how long and severe it's already been
			$sign=-1;
			if ($shortage > 1.0) {
				// how is this even possible?
				$this->logger->warning("shortage $shortage in ".$settlement->getName());
				$shortage = 1.0;
			}
			$settlement->setStarvation($settlement->getStarvation() + $shortage);

			if ($settlement->getStarvation() > 4) {
				// start to do something against this:
				// first, reduce focus in our buildings
				foreach ($settlement->getBuildings() as $building) {
					if ($building->getFocus()>0) {
						$building->setFocus($building->getFocus()-1);
					}
				}
			}

			if (($settlement->getStarvation() > 8 && $shortage > 0.2) || $settlement->getStarvation() > 15) {
				if ($settlement->getStarvation() > 20 || $shortage > 0.5) {
					$mod = 0.5;
				} elseif ($settlement->getStarvation() > 12 && $shortage > 0.25) {
					$mod = 0.65;
				} else {
					$mod = 0.75;
				}
				$reduced = false;
				foreach ($settlement->getBuildings() as $building) {
					if ($building->getWorkers()>0) {
						$building->setWorkers($building->getWorkers() * $mod);
						$reduced = true;
					}
				}
				foreach ($settlement->getGeoData()->getRoads() as $road) {
					if ($road->getWorkers()>0) {
						$road->setWorkers($road->getWorkers() * $mod);
						$reduced = true;
					}
				}
				foreach ($settlement->getGeoData()->getFeatures() as $feature) {
					if ($feature->getWorkers()>0) {
						$feature->setWorkers($feature->getWorkers() * $mod);
						$reduced = true;
					}
				}
				if ($reduced) {
					$this->history->logEvent(
						$settlement,
						'event.settlement.setback',
						array(),
						History::MEDIUM, false, 15
					);
				}
			}
			
			if ($settlement->getStarvation() < 10) {
				// starvation effect = (x/10)^2 - meaning that the first few days it's barely noticeable, and full effect after 10 days of 0 food or the equivalent of some shortage
				$starvation = pow($settlement->getStarvation()/10,2); 
				if ($settlement->getStarvation() >= 9) {
					$this->history->logEvent(
						$settlement,
						'event.settlement.hunger',
						array(),
						History::MEDIUM, false, 10
					);
				}
			} else {
				// full effect after 10 full days
				$starvation = 1.0;
				$this->history->logEvent(
					$settlement,
					'event.settlement.starvation',
					array(),
					History::HIGH, false, 10
				);

				// now we have hit starvation, it's time to cancel trades
				$food_resource = $this->em->getRepository('BM2SiteBundle:ResourceType')->findOneByName('food');
				$trade_shortage = ceil($shortage * $settlement->getFullPopulation() * 0.25);
				$this->reduceTrade($settlement, $food_resource, $trade_shortage);

			}
			$shortage *= $starvation;
		} else {
			$sign=1;
			$settlement->setStarvation(max(0, $settlement->getStarvation() + ($shortage-0.25)*2)); // recover from starvation effects - the + is correct because $shortage is negative here
		}

		// TODO: take other resources, especially goods, into consideration as well
		// TODO: only grow when we've had a bit of a surplus for some days, otherwise we will never have a reserve, I think. 
		//			Basically, population should not follow food production so closely as it does now
		//			we could code this by checking for the surplus storage
		$growth = sqrt(1-exp(-pow($shortage,2)))*$sign;
		$popchange = $sign*round(pow(abs($growth) * $settlement->getPopulation(),0.666));
                /* Commenting this out to bring code in line with Tom's Server. It doesn't actually do anything. -- Andrew
                if ($popchange > 0) {
                        $goods = $settlement->findResource($this->em->getRepository('BM2SiteBundle:ResourceType')->findOneByName('goods'));
                        $wealth = $settlement->findResource($this->em->getRepository('BM2SiteBundle:ResourceType')->findOneByName('money'));
                        $mod = min($goods->getSupply(), $wealth->getSupply());
                        echo "growth of ".$settlement->getName()." limited by $mod\n";
                }
                */
		$settlement->setPopulation($settlement->getPopulation() + $popchange);

		// thralls drop faster and rise slower
		if ($sign > 0) {
			$thrallschange = round(0.75 * pow(abs($growth) * $settlement->getThralls(),0.666));
		} else {
			$thrallschange = round(-1.5 * pow(abs($growth) * $settlement->getThralls(),0.666));
		}
		if ($settlement->getAllowThralls()==false && $thrallschange>0) {
			// if slavery has been forbidden, positive changes in thrall population go to peasants instead
			$settlement->setPopulation($settlement->getPopulation() + $thrallschange);
		} else {
			$settlement->setThralls($settlement->getThralls() + $thrallschange);
		}

		// effect on troops (militia and mobile)
		// soldiers can eat with a little shortage, else they would almost never get anything,
		// because moving into a region in balance drops food supply
		if ($shortage>0.25 || $real_shortage>0.25) {
			$severity=1;
			if ($shortage>0.5 || $real_shortage>0.5) $severity++;
			if ($shortage>0.75 || $real_shortage>0.75) $severity++;
			$severity += min(3, round($settlement->getStarvation()/10));
			foreach ($settlement->getSoldiers() as $militia) {
				if ($militia->isAlive()) {
					$militia->makeHungry($severity);
					// militia can take several days of starvation without danger of death
					if (rand(100, 200) < $militia->getHungry()) {
						$militia->kill();
						$this->history->addToSoldierLog($militia, 'starved');
					}
				}
			}
			foreach ($this->geo->findCharactersInArea($settlement->getGeoData()) as $char) {
				if ($char->getInsideSettlement() == $settlement) {
					$my_severity = $severity;
				} else {
					// outside settlement, it becomes very tricky in starvation times
					// (this is mostly to balance sieges)
					$my_severity = round($severity*1.75);
				}
				$this->feedSoldiers($char, $my_severity);
			}
		} else {
			// got food
			foreach ($settlement->getMilitia() as $militia) {
				if ($militia->isAlive()) {		
					$militia->feed();
				}
			}
			foreach ($this->geo->findCharactersInArea($settlement->getGeoData()) as $char) {
				foreach ($char->getLivingSoldiers() as $soldier) {
					$soldier->feed();
				}
				foreach ($char->getLivingEntourage() as $ent) {
					$ent->feed();
				}
			}
		}
	}

	public function feedSoldiers(Character $char, $my_severity) {
		$my_severity = min($my_severity, 6); // can't starve to death in less than 10 days or so
		$food_followers = $char->getEntourage()->filter(function($entry) {
			return ($entry->getType()->getName()=='follower' && $entry->isAlive() && !$entry->getEquipment() && $entry->getSupply()>0);
		})->toArray();
		foreach ($char->getLivingSoldiers() as $soldier) {
			if (!empty($food_followers)) {
				$soldier->feed();
				shuffle($food_followers);
				$has = $food_followers[0]->getSupply();
				if ($has<=1) {
					$food_followers[0]->setSupply(0);
					unset($food_followers[0]);
				} else {
					$food_followers[0]->setSupply($has-1);
				}
			} else {
				$soldier->makeHungry($my_severity);
				// soldiers can take several days of starvation without danger of death, but slightly less than militia (because they move around, etc.)
				if (rand(90, 180) < $soldier->getHungry()) {
					$soldier->kill();
					$this->history->addToSoldierLog($soldier, 'starved');
				}
			}
		}
		foreach ($char->getLivingEntourage() as $ent) {
			if (!empty($food_followers)) {
				$ent->feed();
				shuffle($food_followers);
				$has = $food_followers[0]->getSupply();
				if ($has<=1) {
					$food_followers[0]->setSupply(0);
					unset($food_followers[0]);
				} else {
					$food_followers[0]->setSupply($has-1);
				}
			} else {
				$ent->makeHungry($my_severity);
				// entourage also can take several days of starvation without danger of death, like soldiers
				if (rand(80, 160) < $ent->getHungry()) {
					$ent->kill();
				}
			}
		}
		// TODO: event to the character if we use up food from followers? - and also when we don't have any left?
	}

	public function ResourceProduction(Settlement $settlement, ResourceType $resource, $ignore_buildings=false, $force_recalc=false) {
		$georesource = $settlement->findResource($resource);
		if ($georesource) {
			$baseresource = $georesource->getAmount();
		} else {
			$baseresource = 0;
		}
		if ($ignore_buildings) {
			$building_resource=0; $building_bonus=1.0;
		} else {
			if ($force_recalc) {
				list($building_resource, $building_bonus) = $this->ResourceFromBuildings($settlement, $resource, $baseresource);
				$georesource->setBuildingsBase($building_resource);
				$georesource->setBuildingsBonus(round($building_bonus*100));
			} else {
				$building_resource = $georesource->getBuildingsBase();
				$building_bonus = $georesource->getBuildingsBonus()/100;
			}
		}
		$baseresource += $building_resource;
		if ($baseresource<=0) return 0;

		// stationed militia contributes 50% to the local economy
/*
	this old code is more transparent, but takes a lot longer
		$militia_bonus = round($settlement->getMilitia()->count() / 2);
*/
		$query = $this->em->createQuery('SELECT count(s) FROM BM2SiteBundle:Soldier s JOIN s.base b WHERE b = :here AND s.training_required <= 0');
		$query->setParameter('here', $settlement);
		$militia_bonus = $query->getSingleScalarResult() * 0.5;
		$workforce = $settlement->getAvailableWorkforce() + $militia_bonus;
		if ($workforce <= 0) {
			return 0;
		}

		// formula is y=ln(y*(x+1/y)) with 
		// x = workforce/baseresource and y an arbitrary growth factor. 
		// 3 seems to work out to about 2x food = sustainable population
		// the +1/3 is just to ensure that 0 = 0
		if ($resource->getName() == 'food') {
			// food is the exception, it has a base value that will work even with 0 population
			$production = log(3 * (($workforce / $baseresource) + 1));
		} else {
			$production = log(3 * (($workforce / $baseresource) + 1/3));
		}
		
		switch ($resource->getName()) {
			case 'food':	// security bonus - only for food and money
			case 'money':
				$production *= $this->EconomicSecurity($settlement);
				break;
			case 'goods':	// goods need networking effects - this will give full production at 16k people, half at 4k and 1/4th at 1k
				$production *= min(4, sqrt($settlement->getFullPopulation()/2000))/4.0;
				break;
		}

		/* TODO: 
			land-intensive resources (food, to a lesser extent wood, even less metal) should decline
			with increasing population density as more and more land is used as living space
			basically: production *= min(1, x/density);

			if x were, say, 1000 then up to 1000 people / square mile it wouldn't matter, at 2000 food production would be halved,
			at 3000 it would be 1/3rd, etc. - yes, at 10,000 it would only be 10% - but remember that it also scales up with population above, so the graph is actually a bit more interesting.

			one idea would be to actually calculate an "arabale land area" value for a geodata. but that would only make sense for food, at least using that term.

			One or more of these ideas would make regions possible that create constant amounts of surplus food without simply growing 
			because of it (or rather, which have an equilibrium point with surplus food).
		*/

		$total_production = round($production*$baseresource*$building_bonus);

		return $total_production;
	}

	public function ResourceFromBuildings(Settlement $settlement, ResourceType $resource) {
		$query = $this->em->createQuery('SELECT r FROM BM2SiteBundle:BuildingResource r JOIN r.resource_type t JOIN r.building_type bt JOIN bt.buildings b JOIN b.settlement s
			WHERE s=:here AND t=:resource AND b.active=true AND (r.provides_operation>0 OR r.provides_operation_bonus>0)');
		$query->setParameters(array('here'=>$settlement, 'resource'=>$resource));
		$base = 0; $bonus = 0;
		foreach ($query->getResult() as $result) {
			$base += $result->getProvidesOperation();
			$bonus+= $result->getProvidesOperationBonus();
		}
		return array($base, 1.0+($bonus/100));
	}

	public function ResourceDemand(Settlement $settlement, ResourceType $resource, $split_results=false) {
		// this is the population used for all resources except food, which has its own calculation
		$militia = $settlement->getSoldiers()->count();
		$population = $settlement->getPopulation() + $settlement->getThralls()/2 + $militia/2;

		$buildings_operation = $this->ResourceForBuildingOperation($settlement, $resource);
		$buildings_construction = $this->ResourceForBuildingConstruction($settlement, $resource);

		switch (strtolower($resource->getName())) {
			case 'food':
				$query = $this->em->createQuery('SELECT count(s) FROM BM2SiteBundle:Character c JOIN c.soldiers s, BM2SiteBundle:GeoData g WHERE ST_Contains(g.poly, c.location)=true AND g.id=:here AND s.base IS NULL AND s.alive=true');
				$query->setParameter('here', $settlement->getGeoData());
				$soldiers = $query->getSingleScalarResult();
				$query = $this->em->createQuery('SELECT count(e) FROM BM2SiteBundle:Character c JOIN c.entourage e, BM2SiteBundle:GeoData g WHERE ST_Contains(g.poly, c.location)=true AND g.id=:here AND e.alive=true');
				$query->setParameter('here', $settlement->getGeoData());
				$entourage = $query->getSingleScalarResult();
				// mobile soldiers and entourage take a little food "magically" from hunting and scavenging. Also, to reduce the impact of large armies marching through
				$need = $settlement->getPopulation() + $settlement->getThralls()*0.75 + $militia + ($soldiers + $entourage)*0.8;
				break;
			case 'wood':
				$base = sqrt($population) + exp(sqrt($population)/150) - 5;
				$need = max(0, $base*10);
				break;
			case 'metal':
				$base = sqrt($population) + exp(sqrt($population)/250) - 20;
				$need = max(0, $base*10);
				break;
			case 'goods':
				$base = sqrt($population) + exp(sqrt($population)/200) - 25;
				$need = max(0, $base*5.0);
				break;
			default:
				$need = 0;
		}

		$total = $need + $buildings_operation + $buildings_construction;
		$corruption = $this->calculateCorruption($settlement);
		if ($split_results) {
			return array(
				'base' => round($need), 
				'operation' => round($buildings_operation), 
				'construction' => round($buildings_construction), 
				'corruption' => round($total * $corruption)
			);
		} else {
			return round($total * (1 + $this->calculateCorruption($settlement)));
		}
	}

	public function ResourceForBuildingOperation(Settlement $settlement, ResourceType $resource) {
		$query = $this->em->createQuery('SELECT r, b.focus FROM BM2SiteBundle:BuildingResource r JOIN r.resource_type t JOIN r.building_type bt JOIN bt.buildings b JOIN b.settlement s WHERE s=:here AND t=:resource AND b.active=true and r.requires_operation > 0');
		$query->setParameters(array('here'=>$settlement, 'resource'=>$resource));
		$amount = 0;
		foreach ($query->getResult() as $result) {
			$res = $result[0];
			$focus = $result['focus'];
			$amount += $res->getRequiresOperation() * pow(2, $focus);
		}

		// population size scaling
		$amount *= log(($settlement->getFullPopulation()/1000)+1);

		return round($amount);
	}

	public function ResourceForBuildingConstruction(Settlement $settlement, ResourceType $resource) {
		$query = $this->em->createQuery('SELECT b as building, r.requires_construction as required, bt.build_hours as buildHours FROM BM2SiteBundle:Building b JOIN b.type bt JOIN bt.resources r JOIN r.resource_type rt JOIN b.settlement s WHERE s=:here AND rt=:resource AND b.active=false');
		$query->setParameters(array('here'=>$settlement, 'resource'=>$resource));

		$total = 0;
		foreach ($query->getResult() as $result) {
			$perhour = $result['required'] / $result['buildHours'];
			$workhours = $this->calculateWorkHours($result['building'], $settlement);
			$total += ceil($perhour * $workhours);
		}

		return $total;
	}

	public function EconomicSecurity(Settlement $settlement) {
		$security = 1.0;

		// militia patrolling the area will drive off wild animals and bandits, but beyond 100 there's no additional effect
		$militia = $settlement->getActiveMilitia()->count();
		$pop = $settlement->getPopulation();
		if ($pop < 100) {
			$militia *= 2.0;
		} elseif ($pop < 200) {
			$militia *= 1.8;
		} elseif ($pop < 400) {
			$militia *= 1.6;
		} elseif ($pop < 600) {
			$militia *= 1.4;
		} elseif ($pop < 800) {
			$militia *= 1.2;
		}
		if ($militia >= 100) {
			$security += 0.1;
		} else {
			$security += sqrt($militia/100)/10; 
		}

		$thralls = $settlement->getThralls();
		if ($thralls > 0) {
			$check = $thralls / ($militia * 10 + $pop);
			if ($check < 0.2) {
				$security -= 0.01;
			} elseif ($check < 0.4) {
				$security -= 0.02;
			} elseif ($check < 0.6) {
				$security -= 0.04;
			} elseif ($check < 0.8) {
				$security -= 0.08;
			} elseif ($check < 2.0) {
				$security -= $check/10;
			} else {
				$security -= $check/8;
			}
		}

		// fortifications also grant security - animals can't cross a palisade, bandits avoid towers
		if ($settlement->hasBuildingNamed('Palisade')) {
			$security += 0.03;
		}
		if ($settlement->hasBuildingNamed('Wood Wall')) {
			$security += 0.02;
		}
		if ($settlement->hasBuildingNamed('Wood Towers')) {
			$security += 0.05;
		}
		
		/* The following can't affect the result since 0.2 + 0.12 from buildings + 0.05 from population is always less than 1.0
		if ($security < 0.2) {
			// if you are insecure, then some fake security works, too:
			if ($settlement->hasBuildingNamed('Shrine')) {
				$security += 0.02;
			}
			if ($settlement->hasBuildingNamed('Temple')) {
				$security += 0.05;
			}
			if ($settlement->hasBuildingNamed('Great Temple')) {
				$security += 0.05;
			}
		}
		*/
		
		// finally, there's security in numbers - wild animals will avoid large settlements
		if ($pop > 4000) {
			$security += 0.05;
		} elseif ($pop > 2000) {
			$security += 0.04;
		} elseif ($pop > 1000) {
			$security += 0.03;
		} elseif ($pop > 500) {
			$security += 0.02;
		} elseif ($pop > 250) {
			$security += 0.01;
		}

		return max(1.0, $security);
	}


	public function TradeBalance(Settlement $settlement, ResourceType $resource) {
		$amount = 0;

		$query = $this->em->createQuery('SELECT t FROM BM2SiteBundle:Trade t WHERE t.resource_type = :resource AND (t.source = :here OR t.destination = :here)');
		$query->setParameters(array('resource'=>$resource, 'here'=>$settlement));

		foreach ($query->getResult() as $trade) {
			if ($trade->getDestination() == $settlement) {
				// incoming trade
				$amount += $trade->getAmount();
			} else {
				// outgoing trade
				$amount -= $trade->getAmount();
			}
		}

		return $amount;
	}

	public function TradeCostBetween(Settlement $a, Settlement $b, $have_merchant = false) {
		$distance = $this->geo->calculateDistanceBetweenSettlements($a, $b);

		if ($have_merchant) {
			$base = 15000;
		} else {
			$base = 10000;
		}
		// these numbers have been arrived at by eye-balling.
		// they give a minimum of 1% then at 5km = 1%, 10km = 2%, 20km = 4%, 30km = 6%, 40km = 9%, 50km = 12%, 75km = 22%, 100km = 36%, up to 100% at about 180km
		$cost = pow($base+($distance/2), 2)/pow($base, 2);

		// TODO: some buildings should reduce this cost, e.g. the Merchant's District

		return $cost/100;
	}

	public function ResourceAvailable(Settlement $settlement, ResourceType $resource) {
		return $this->ResourceProduction($settlement, $resource) + $this->TradeBalance($settlement, $resource);
	}


	public function RoadConstruction(Road $road, $length, $mod) {
		$required = $this->RoadHoursRequired($road, $length, $mod);
		$workhours = $this->calculateWorkHours($road);

		if ($road->getCondition() + $workhours >= $required) {
			$road->setCondition(0);
			$road->setQuality($road->getQuality()+1);
			$road->setWorkers(0);
			return true;
		} else {
			$road->setCondition($road->getCondition()+round($workhours));
			return false;
		}
	}

	public function RoadHoursRequired(Road $road, $length, $mod) {
		$required = ($road->getQuality()*2+1) * $length * $mod;
		return $required;
	}

	public function BuildingProduction(Building $building, $supply) {
		$employees = $building->getEmployees();
		$max = $employees * 500; // 100 work days max storage
		$gain = $employees * 5; // add half a work day (10 hours), because we assume that they spend time on overhead and non-military work, too

		// resources
		$mod = 1.0;
		foreach ($building->getType()->getResources() as $req) {
			if ($req->getRequiresOperation()>0) {
				$mod *= $supply[$req->getResourceType()->getId()];
			}
		}

		if ($building->getSettlement()->getPopulation() > 0) {
			// settlement size - counter the workforce increase in Building->getEmployees and then some
			if ($building->getSettlement()->getPopulation() < $building->getType()->getMinPopulation() * 2 ) {
				$mod *= pow($building->getSettlement()->getPopulation() / ($building->getType()->getMinPopulation() * 2), 1.5);
			}
		} else {
			// no population also produces nothing
			$mod = 0.0;
		}

/*
	FIXED: this adds focus, but that's wrong because focus increases the employees and adding it here would make it count twice
		$focus = pow(1.5, $building->getFocus());
		$gain = round($gain*$mod*$focus);
*/
		$gain = round($gain*$mod);
		$building->setCurrentSpeed(min(1.0, $mod));

		// max storage, but we never drop below what we had, even if population drops
		$building->setResupply(max($building->getResupply(),min($max,$building->getResupply()+$gain)));
	}

	public function BuildingConstruction(Building $building, $supply) {
		if ($building->getWorkers()<=0) {
			// abandoned - fall into disrepair
			$takes = $building->getType()->getBuildHours();
			$loss = rand(20, $takes/100) + rand(0, $takes/200);
			if ($building->getCondition() > -1000) {
				// faster decay if close to finished
				$loss += rand(20, 100);
			}
			$building->setCondition($building->getCondition() - $loss);
			if (abs($building->getCondition()) > $takes) {
				// destroyed
				$this->history->logEvent(
					$building->getSettlement(),
					'event.settlement.disrepair',
					array('%link-buildingtype%'=>$building->getType()->getId()),
					History::HIGH, false, 30
				);
				$this->em->remove($building);
				$building->getSettlement()->removeBuilding($building);
			}
			// stockpile also drops
			$building->setResupply(max(0,$building->getResupply() - $loss));
		} else {
			// resources
			$mod = 1.0;
			foreach ($building->getType()->getResources() as $req) {
				if ($req->getRequiresConstruction()>0) {
					$mod *= $supply[$req->getResourceType()->getId()];
				}
			}
			$workhours = $this->calculateWorkHours($building) * $mod;
			$building->setCurrentSpeed($mod);

			if ($building->getCondition() + $workhours >= 0) {
				$building->setActive(true);
				$building->setCondition(0);
				$building->setWorkers(0);
				return true;
			} else {
				$building->setCondition($building->getCondition()+round($workhours));
			}
		}
        return false;
	}

	public function FeatureConstruction(GeoFeature $feature) {
		$workhours = $this->calculateWorkHours($feature);

		if ($feature->getCondition() + $workhours >= 0) {
			$feature->setActive(true);
			$feature->setCondition(0);
			$feature->setWorkers(0);			
			return true;
		} else {
			$feature->setCondition($feature->getCondition()+round($workhours));
			return false;
		}
	}


	public function calculateWorkHours($entity, $settlement=null) {
		if ($entity->getWorkers()<=0) return 0;
		if ($entity instanceof Building) {
			if (!$settlement) { $settlement = $entity->getSettlement(); }
			$workers = round($entity->getWorkers() * $settlement->getPopulation());
			if ($workers<=0) return 0;
			return pow($workers, 0.95) * $this->hours_per_day;
		} elseif ($entity instanceof Road) {
			if (!$settlement) { $settlement = $entity->getGeoData()->getSettlement(); }
			$workers = round($entity->getWorkers() * $settlement->getPopulation());
			return pow($workers, 0.975) * $this->hours_per_day;
		} elseif ($entity instanceof GeoFeature) {
			if (!$settlement) { $settlement = $entity->getGeoData()->getSettlement(); }
			$workers = round($entity->getWorkers() * $settlement->getPopulation());
			$workhours = pow($workers, 0.95) * $this->hours_per_day;
			$workhours /= $entity->getGeoData()->getBiome()->getFeatureConstruction();
			return $workhours;
		}
		$this->logger->error('unknown entity type for workhours calculation');
		return 0;
	}


	public function calculateCorruption(Settlement $settlement) {
		if (false === $settlement->corruption) {
			$estates = 0;
			if ($settlement->getOwner()) {
				$user = $settlement->getOwner()->getUser();
				$query = $this->em->createQuery('SELECT count(s) FROM BM2SiteBundle:Settlement s JOIN s.owner c WHERE c.user = :user');
				$query->setParameter('user', $user);
				$estates = $query->getSingleScalarResult();
			}
			$settlement->corruption = $estates/500;
		}
		return $settlement->corruption;
	}

}
