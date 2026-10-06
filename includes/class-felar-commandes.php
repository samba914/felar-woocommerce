<?php
/**
 * Les commandes de la boutique vers Felar.
 *
 * <h2>Envoyée dès qu'elle existe, payée ou non</h2>
 * Attendre le règlement laisserait passer les commandes à payer à la livraison —
 * la moitié du commerce ici — et Felar ne réserverait le stock que trop tard. La
 * commande part donc à sa création, et son règlement suit par un second appel.
 *
 * <h2>Rien n'est envoyé depuis la requête du client</h2>
 * Un appel réseau pendant la validation du panier allonge l'attente de l'acheteur,
 * et une panne de Felar ferait échouer sa commande. L'envoi passe donc par une
 * tâche de fond : le client voit sa confirmation tout de suite, et la commande
 * monte dans la minute.
 *
 * <h2>Rejouer est sans danger</h2>
 * Felar reconnaît le couple (compte, source, identifiant) et renvoie la commande
 * déjà enregistrée plutôt qu'une jumelle. C'est ce qui permet de réessayer après
 * une coupure sans rien vérifier d'abord — et de brancher deux crochets sur le
 * même événement sans craindre le doublon.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Commandes {

	const OPTION = 'felar_connect_commandes';
	const ACTION = 'felar_connect_commande';
	const ETAT   = 'felar_connect_commande_etat';
	const REMBOURSEMENT = 'felar_connect_commande_remboursement';
	const GROUPE = 'felar-connect';

	/** L'identifiant Felar de la commande, et son numéro chez le marchand. */
	const META_ID        = '_felar_commande_id';
	const META_REFERENCE = '_felar_commande_reference';

	/** Vingt refus gardés : de quoi comprendre, pas de quoi remplir la base. */
	const REFUS_MAX = 20;

	/** Trois tentatives sur une panne passagère. */
	const TENTATIVES_MAX = 3;

	/** @var Felar_Reglages */
	private $reglages;

	/** @var Felar_Commande_Lecteur */
	private $lecteur;

	public function __construct( Felar_Reglages $reglages, Felar_Commande_Lecteur $lecteur ) {
		$this->reglages = $reglages;
		$this->lecteur  = $lecteur;
	}

	/** Branche les crochets de WooCommerce et le traitement des tâches. */
	public function brancher() {
		add_action( self::ACTION, array( $this, 'envoyer' ), 10, 2 );
		add_action( self::ETAT, array( $this, 'pousser_letat' ), 10, 3 );
		add_action( self::REMBOURSEMENT, array( $this, 'envoyer_le_remboursement' ), 10, 3 );

		if ( ! $this->actif() ) {
			return;
		}

		add_action( 'woocommerce_new_order', array( $this, 'a_la_creation' ), 20, 1 );

		// Deux crochets pour le règlement, volontairement : `payment_complete` ne
		// part pas quand le marchand marque une commande payée à la main, et le
		// changement d'état ne part pas toujours sur les passerelles qui encaissent
		// sans transition. Les deux ensemble couvrent les deux chemins, et le second
		// envoi est sans conséquence puisque Felar n'encaisse pas deux fois.
		add_action( 'woocommerce_payment_complete', array( $this, 'au_reglement' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'au_changement' ), 20, 4 );

		// Un remboursement PARTIEL ne change pas l'état de la commande : elle reste
		// « terminée ». Sans ce crochet-là, il passerait inaperçu — et c'est le cas
		// le plus fréquent, un seul article rendu sur trois.
		add_action( 'woocommerce_order_refunded', array( $this, 'au_remboursement' ), 20, 2 );
	}

	/** Vrai si le marchand a mis l'envoi en route. */
	public function actif() {
		$etat = $this->etat_brut();
		return ! empty( $etat['actif'] ) && $this->reglages->branchee();
	}

	/** Une commande vient d'être créée. */
	public function a_la_creation( $identifiant ) {
		$this->enfiler( (int) $identifiant );
	}

	/** Le règlement est arrivé. */
	public function au_reglement( $identifiant ) {
		$this->enfiler_un_etat( (int) $identifiant, 'PAID' );
	}

	/**
	 * L'état de la commande a changé.
	 *
	 * @param int    $identifiant L'identifiant de la commande.
	 * @param string $avant       L'état précédent.
	 * @param string $apres       Le nouvel état.
	 * @param mixed  $commande    L'objet commande.
	 */
	public function au_changement( $identifiant, $avant, $apres, $commande = null ) {
		$identifiant = (int) $identifiant;

		if ( 'cancelled' === $apres || 'failed' === $apres ) {
			$this->enfiler_un_etat( $identifiant, 'CANCELLED' );
			return;
		}

		if ( 'refunded' === $apres ) {
			// Rien à faire ici, et c'est voulu. WooCommerce fait passer la commande à
			// « remboursée » APRÈS avoir créé le remboursement, qui a déjà déclenché
			// `woocommerce_order_refunded`. L'enfiler une seconde fois renverrait les
			// mêmes lignes : Felar les refuserait pour dépassement du livré, et le
			// marchand lirait un échec sur une opération parfaitement réussie.
			return;
		}

		// Le règlement AVANT la livraison, et les deux sur le même passage : une
		// commande qui va directement de « en attente » à « terminée » — le cas d'un
		// paiement à la livraison encaissé au moment de la remise — est payée ET
		// livrée. Traiter « terminée » à part, comme on le faisait, la marquait
		// livrée sans jamais la marquer payée : elle restait impayée dans Felar, et
		// son chiffre d'affaires manquait à la caisse.
		$payes = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		if ( in_array( $apres, $payes, true ) && ! in_array( $avant, $payes, true ) ) {
			$this->enfiler_un_etat( $identifiant, 'PAID' );
		}

		if ( 'completed' === $apres ) {
			// La marchandise est partie. Sans cette annonce, la réservation posée à
			// l'entrée de la commande tiendrait pour toujours : le disponible du
			// marchand baisserait d'une unité à chaque vente web et ne remonterait
			// jamais. C'est la panne la plus lente de ce connecteur, et elle ne se
			// voit qu'au bout de quelques semaines.
			$this->enfiler_un_etat( $identifiant, 'DELIVERED' );
		}
	}

	/**
	 * Un remboursement vient d'être enregistré dans WooCommerce.
	 *
	 * @param int $commande Identifiant de la commande.
	 * @param int $remboursement Identifiant du remboursement.
	 */
	public function au_remboursement( $commande, $remboursement ) {
		$this->enfiler_un_remboursement( (int) $commande, (int) $remboursement );
	}

	/** Met un remboursement dans la file. */
	public function enfiler_un_remboursement( $identifiant, $remboursement = 0, $tentative = 0 ) {
		if ( $identifiant <= 0 || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::REMBOURSEMENT,
			array( $identifiant, $remboursement, $tentative ), self::GROUPE );
	}

	/**
	 * Envoie un remboursement à Felar, avec ses lignes.
	 *
	 * <h3>Ce qu'on envoie, et ce qu'on tait</h3>
	 * Les articles rendus et leur quantité — rien d'autre. Un remboursement qui ne
	 * porterait qu'un montant ne dirait pas ce que devient la marchandise, et Felar
	 * le refuse plutôt que de l'enregistrer à moitié. Un geste commercial sur un
	 * montant se traite donc dans Felar, sur l'écran de la commande.
	 *
	 * @param int $identifiant   La commande.
	 * @param int $remboursement Le remboursement précis, ou 0 pour tous.
	 * @param int $tentative     Le rang de la tentative.
	 */
	public function envoyer_le_remboursement( $identifiant, $remboursement = 0, $tentative = 0 ) {
		$commande = wc_get_order( (int) $identifiant );
		if ( ! $commande ) {
			return;
		}

		$lignes = $this->lignes_rendues( $commande, (int) $remboursement );
		if ( empty( $lignes ) ) {
			// Un remboursement sans ligne : un geste commercial sur un montant. Felar
			// le refuserait, et il a raison — il ne dit rien de la marchandise.
			$this->retenir_un_refus( $identifiant, 'REMBOURSEMENT_SANS_LIGNE',
				"Ce remboursement ne porte aucun article : Felar ne saurait pas quoi faire du stock. Traitez-le dans Felar, sur l'écran de la commande." );
			return;
		}

		$resultat = $this->reglages->client()->rembourser(
			(string) $identifiant,
			array(
				'lines'  => $lignes,
				'reason' => 'Remboursement enregistré dans WooCommerce',
			)
		);

		if ( 'ok' !== $resultat['issue'] ) {
			$this->rejouer_ou_renoncer( $identifiant, $resultat, $tentative,
				self::REMBOURSEMENT, $remboursement );
			return;
		}

		$etat = $this->etat_brut();
		$this->oublier_le_refus( $etat, $identifiant );
		$etat['compteurs']['etats']++;
		$etat['dernier'] = time();
		$this->enregistrer( $etat );

		foreach ( (array) ( isset( $resultat['donnees']['warnings'] ) ? $resultat['donnees']['warnings'] : array() ) as $avertissement ) {
			$this->retenir_un_refus( $identifiant,
				isset( $avertissement['code'] ) ? $avertissement['code'] : '',
				isset( $avertissement['message'] ) ? $avertissement['message'] : '', false );
		}
	}

	/**
	 * Les articles rendus, en quantités positives.
	 *
	 * WooCommerce range les quantités remboursées en NÉGATIF — c'est ainsi qu'il
	 * les soustrait de la commande. Felar attend une quantité rendue, donc positive :
	 * envoyer le signe tel quel ferait refuser chaque ligne.
	 */
	private function lignes_rendues( $commande, $remboursement ) {
		$rendues = array();

		foreach ( $commande->get_refunds() as $retour ) {
			if ( $remboursement > 0 && (int) $retour->get_id() !== $remboursement ) {
				continue;
			}
			foreach ( $retour->get_items() as $article ) {
				$quantite = abs( (float) $article->get_quantity() );
				if ( $quantite <= 0 ) {
					continue;
				}
				$variation = (int) $article->get_variation_id();
				$produit   = $variation > 0 ? $variation : (int) $article->get_product_id();
				$objet     = $article->get_product();

				$rendues[] = array(
					'externalId' => (string) $produit,
					'reference'  => $objet ? (string) $objet->get_sku() : '',
					'quantity'   => $quantite,
				);
			}
		}

		return $rendues;
	}

	/** Met une commande dans la file. */
	public function enfiler( $identifiant, $tentative = 0 ) {
		if ( $identifiant <= 0 || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::ACTION, array( $identifiant, $tentative ), self::GROUPE );
	}

	/** Met un changement d'état dans la file. */
	public function enfiler_un_etat( $identifiant, $statut, $tentative = 0 ) {
		if ( $identifiant <= 0 || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		as_enqueue_async_action( self::ETAT, array( $identifiant, $statut, $tentative ), self::GROUPE );
	}

	/**
	 * Envoie une commande à Felar.
	 *
	 * @param int $identifiant L'identifiant WooCommerce.
	 * @param int $tentative   Le rang de la tentative.
	 */
	public function envoyer( $identifiant, $tentative = 0 ) {
		$identifiant = (int) $identifiant;
		$brut        = $this->lecteur->lire( $identifiant );

		if ( null === $brut ) {
			// La commande a disparu entre la mise en file et le réveil de la tâche.
			return;
		}

		$conversion = $this->convertisseur()->convertir( $brut );
		if ( null === $conversion['payload'] ) {
			$this->retenir_un_refus(
				$identifiant,
				$conversion['refus'],
				Felar_Commande_Convertisseur::phrase( $conversion['refus'] )
			);
			return;
		}

		$resultat = $this->reglages->client()->envoyer_commande( $conversion['payload'] );

		if ( 'ok' !== $resultat['issue'] ) {
			$this->rejouer_ou_renoncer( $identifiant, $resultat, $tentative, self::ACTION );
			return;
		}

		$this->retenir_le_lien( $identifiant, $resultat['donnees'] );

		$etat = $this->etat_brut();
		$this->oublier_le_refus( $etat, $identifiant );
		if ( ! empty( $resultat['donnees']['duplicate'] ) ) {
			$etat['compteurs']['doublons']++;
		} else {
			$etat['compteurs']['envoyees']++;
		}
		foreach ( (array) ( isset( $resultat['donnees']['warnings'] ) ? $resultat['donnees']['warnings'] : array() ) as $avertissement ) {
			$code = isset( $avertissement['code'] ) ? (string) $avertissement['code'] : '';
			if ( '' !== $code ) {
				$etat['avertissements'][ $code ] = isset( $etat['avertissements'][ $code ] )
					? $etat['avertissements'][ $code ] + 1
					: 1;
			}
		}
		$etat['dernier'] = time();
		$this->enregistrer( $etat );

		// Les notes de conversion valent d'être dites : une ligne hors catalogue ou
		// un client sans contact changent ce que le marchand verra dans Felar.
		foreach ( $conversion['notes'] as $note ) {
			$this->retenir_un_refus( $identifiant, $note, Felar_Commande_Convertisseur::phrase( $note ), false );
		}
	}

	/**
	 * Annonce la suite d'une commande.
	 *
	 * @param int    $identifiant L'identifiant WooCommerce.
	 * @param string $statut      `PAID` ou `CANCELLED`.
	 * @param int    $tentative   Le rang de la tentative.
	 */
	public function pousser_letat( $identifiant, $statut, $tentative = 0 ) {
		$identifiant = (int) $identifiant;
		$brut        = $this->lecteur->lire( $identifiant );
		if ( null === $brut ) {
			return;
		}

		$corps = array( 'status' => $statut );
		if ( 'PAID' === $statut ) {
			$paiement = $brut['paiement'];
			if ( '' !== $paiement['methode'] ) {
				$corps['method'] = $paiement['methode'];
			}
			if ( '' !== $paiement['paye_le'] ) {
				$corps['paidAt'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $paiement['paye_le'] ) );
			}
			$corps['amount'] = Felar_Contrat::montant( $brut['total'], $this->devise() );
		}

		$resultat = $this->reglages->client()->changer_letat( (string) $identifiant, $corps );

		if ( 'ok' === $resultat['issue'] ) {
			$etat = $this->etat_brut();
			$this->oublier_le_refus( $etat, $identifiant );
			$etat['compteurs']['etats']++;
			$etat['dernier'] = time();
			$this->enregistrer( $etat );
			return;
		}

		if ( 'fatal' === $resultat['issue'] && 'ORDER_NOT_FOUND' === $resultat['code'] ) {
			// L'état précède la commande : cela arrive quand le marchand active
			// l'envoi entre les deux. On envoie la commande, et son état suivra.
			$this->enfiler( $identifiant );
			return;
		}

		$this->rejouer_ou_renoncer( $identifiant, $resultat, $tentative, self::ETAT, $statut );
	}

	/**
	 * Décide s'il faut réessayer.
	 *
	 * Une panne de réseau se rejoue ; une clé révoquée ou une commande refusée ne se
	 * rejouera jamais avec succès, et insister remplirait le journal sans rien
	 * réparer.
	 */
	private function rejouer_ou_renoncer( $identifiant, array $resultat, $tentative, $quoi, $extra = null ) {
		$rejouable = in_array( $resultat['issue'], array( 'reseau', 'attendre' ), true );

		if ( $rejouable && $tentative < self::TENTATIVES_MAX ) {
			$attente = 'attendre' === $resultat['issue'] ? (int) $resultat['delai'] : 60 * ( $tentative + 1 );

			// La tâche rejouée doit être CELLE QUI A ÉCHOUÉ. Déduire le crochet d'un
			// paramètre nul — comme on le faisait — rejouait l'envoi de la commande
			// quand c'était un remboursement qui venait d'échouer : la demande du
			// marchand se perdait, et une commande déjà connue repartait à sa place.
			if ( self::ETAT === $quoi ) {
				$arguments = array( $identifiant, $extra, $tentative + 1 );
			} elseif ( self::REMBOURSEMENT === $quoi ) {
				$arguments = array( $identifiant, (int) $extra, $tentative + 1 );
			} else {
				$arguments = array( $identifiant, $tentative + 1 );
			}

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $attente, $quoi, $arguments, self::GROUPE );
			}
			return;
		}

		$this->retenir_un_refus( $identifiant, $resultat['code'], $resultat['message'] );
	}

	/**
	 * Retient l'identifiant et le numéro que Felar a donnés.
	 *
	 * Le numéro — `CMD-00142` — est celui que le marchand lit sur ses écrans et sur
	 * sa facture. L'afficher dans l'administration de WooCommerce est le seul moyen
	 * pour lui de retrouver l'une depuis l'autre sans chercher.
	 */
	private function retenir_le_lien( $identifiant, array $reponse ) {
		$commande = wc_get_order( $identifiant );
		if ( ! $commande ) {
			return;
		}

		if ( ! empty( $reponse['felarId'] ) ) {
			$commande->update_meta_data( self::META_ID, sanitize_text_field( $reponse['felarId'] ) );
		}
		if ( ! empty( $reponse['reference'] ) ) {
			$commande->update_meta_data( self::META_REFERENCE, sanitize_text_field( $reponse['reference'] ) );
		}
		$commande->save();
	}

	/**
	 * Garde un refus, ou une simple remarque, pour l'écran.
	 *
	 * @param int    $identifiant La commande concernée.
	 * @param string $code        Le code.
	 * @param string $message     La phrase.
	 * @param bool   $compter     Faux pour une remarque sur une commande acceptée.
	 */
	/**
	 * Efface le refus retenu sur une commande qui vient d'aboutir.
	 *
	 * <h4>Pourquoi un refus doit pouvoir disparaître</h4>
	 * Sans cela, une panne passagère — clé révoquée une heure, Felar indisponible,
	 * une commande refusée puis corrigée — laissait un compteur rouge et une ligne
	 * d'alerte <b>à vie</b> sur l'écran du marchand. Un écran qui ne redevient jamais
	 * propre cesse d'être lu, et c'est précisément celui dont le rôle est de dire
	 * quand quelque chose ne va pas.
	 *
	 * @param array $etat        L'état en cours, modifié sur place.
	 * @param int   $identifiant La commande qui vient de passer.
	 */
	private function oublier_le_refus( array &$etat, $identifiant ) {
		$etat = self::sans_les_refus_de( $etat, $identifiant );
	}

	/**
	 * L'état débarrassé des refus bloquants portant sur cette commande.
	 *
	 * Pure et publique pour être éprouvable sans WordPress : c'est la règle qui
	 * décide si l'écran du marchand peut redevenir propre, et elle mérite des cas.
	 *
	 * @param array $etat        L'état en cours.
	 * @param int   $identifiant La commande qui vient de passer.
	 * @return array L'état nettoyé.
	 */
	public static function sans_les_refus_de( array $etat, $identifiant ) {
		$identifiant = (int) $identifiant;
		$restants    = array();
		$effaces     = 0;

		foreach ( isset( $etat['refus'] ) ? (array) $etat['refus'] : array() as $refus ) {
			// Seuls les refus BLOQUANTS s'effacent. Une simple remarque — « cet
			// article n'était pas encore importé » — décrit la commande telle
			// qu'elle est entrée, et reste vraie après coup.
			if ( (int) $refus['commande'] === $identifiant && ! empty( $refus['bloquant'] ) ) {
				$effaces++;
				continue;
			}
			$restants[] = $refus;
		}

		$etat['refus'] = $restants;
		if ( $effaces > 0 ) {
			// Le compteur suit, sans jamais passer sous zéro : il décrit ce qui
			// reste à regarder, pas une histoire.
			$etat['compteurs']['refusees'] = max( 0, (int) $etat['compteurs']['refusees'] - $effaces );
		}
		return $etat;
	}

	/**
	 * Oublie les refus retenus, sur demande du marchand.
	 *
	 * Il vient de corriger ce qui bloquait — une clé, un réglage de devise, un
	 * article manquant — et il a besoin de repartir d'un écran propre pour voir si
	 * sa correction a pris. Lui demander de désinstaller l'extension pour cela
	 * serait absurde.
	 */
	public function oublier_les_refus() {
		$etat                          = $this->etat_brut();
		$etat['refus']                 = array();
		$etat['compteurs']['refusees'] = 0;
		$this->enregistrer( $etat );
	}

	private function retenir_un_refus( $identifiant, $code, $message, $compter = true ) {
		$etat = $this->etat_brut();

		if ( $compter ) {
			$etat['compteurs']['refusees']++;
			Felar_Journal::noter( 'Commande ' . $identifiant . ' non transmise : ' . $message, 'error' );
		}

		array_unshift(
			$etat['refus'],
			array(
				'commande' => (int) $identifiant,
				'code'     => (string) $code,
				'message'  => (string) $message,
				'bloquant' => (bool) $compter,
				'quand'    => time(),
			)
		);
		$etat['refus']   = array_slice( $etat['refus'], 0, self::REFUS_MAX );
		$etat['dernier'] = time();
		$this->enregistrer( $etat );
	}

	/** Le convertisseur, monté sur la devise du compte. */
	private function convertisseur() {
		return new Felar_Commande_Convertisseur( $this->devise() );
	}

	/** La devise de Felar, celle du compte — la seule qu'il accepte. */
	private function devise() {
		$devise = (string) $this->reglages->lire( 'devise' );
		return '' !== $devise ? $devise : (string) $this->reglages->boutique()['devise'];
	}

	/** Met l'envoi en route, ou l'arrête. */
	public function basculer( $actif ) {
		$etat          = $this->etat_brut();
		$etat['actif'] = (bool) $actif;
		$this->enregistrer( $etat );
		Felar_Journal::noter( $actif ? 'Envoi des commandes activé.' : 'Envoi des commandes arrêté.' );
	}

	/**
	 * Met en file les commandes récentes.
	 *
	 * Sert au marchand qui active l'envoi après coup : les commandes passées
	 * pendant que c'était éteint n'ont aucune raison de rester dehors. Rejouer est
	 * sans danger, donc en reprendre quelques-unes de trop ne coûte rien.
	 *
	 * @param int $combien Nombre de commandes à reprendre.
	 * @return int Le nombre mis en file.
	 */
	public function reprendre_les_recentes( $combien = 25 ) {
		$commandes = wc_get_orders(
			array(
				'limit'   => (int) $combien,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
			)
		);

		foreach ( (array) $commandes as $identifiant ) {
			$this->enfiler( (int) $identifiant );
		}

		return count( (array) $commandes );
	}

	/** L'état brut, complété par ses valeurs par défaut. */
	public function etat_brut() {
		$defauts = array(
			'actif'          => false,
			'dernier'        => 0,
			'compteurs'      => array(
				'envoyees' => 0,
				'doublons' => 0,
				'refusees' => 0,
				'etats'    => 0,
			),
			'avertissements' => array(),
			'refus'          => array(),
		);

		$etat = get_option( self::OPTION, array() );
		return array_merge( $defauts, is_array( $etat ) ? $etat : array() );
	}

	/** Ce que l'écran affiche. */
	public function etat() {
		$etat = $this->etat_brut();

		return array(
			'actif'          => (bool) $etat['actif'],
			'dernier'        => (int) $etat['dernier'],
			'compteurs'      => $etat['compteurs'],
			'avertissements' => $etat['avertissements'],
			'refus'          => $etat['refus'],
			'en_attente'     => $this->taches_en_attente(),
		);
	}

	/** Le nombre de commandes qui attendent leur tour. */
	private function taches_en_attente() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$taches = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'group'    => self::GROUPE,
				'status'   => 'pending',
				'per_page' => 50,
			),
			'ids'
		);
		return count( (array) $taches );
	}

	private function enregistrer( array $etat ) {
		update_option( self::OPTION, $etat, false );
	}

	/** Oublie tout, à la désinstallation. */
	public static function oublier() {
		delete_option( self::OPTION );
	}
}
