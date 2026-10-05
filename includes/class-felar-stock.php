<?php
/**
 * Le stock de Felar vers WooCommerce.
 *
 * <h2>C'est Felar qui tient le stock, et c'est le seul qui le peut</h2>
 * Il bouge à la caisse, à la réception d'une livraison, à l'inventaire, dans une
 * commande annulée — autant de mouvements que le site ne voit pas. WooCommerce
 * n'en connaît qu'une partie : ses propres ventes.
 *
 * <h2>Felar est interrogé, il n'appelle pas</h2>
 * Rien à ouvrir sur le site du marchand, rien à signer, et une panne du site ne
 * perd aucune information : au réveil, l'extension redemande depuis son curseur.
 * La notification signée viendra plus tard, et ce n'est pas un compromis
 * paresseux — le décalage d'affichage n'est pas le vrai risque. Le vrai risque est
 * de vendre deux fois le dernier exemplaire, et cela ne se règle pas à
 * l'affichage : cela se règle à la commande.
 *
 * <h2>Ce que ce passage n'écrit pas</h2>
 * <b>Le stock, et rien d'autre.</b> Le flux porte aussi le prix et l'intitulé ;
 * les reprendre ici écraserait la page que le marchand a écrite et que Google
 * indexe. Un prix corrigé dans Felar ne part sur le site qu'avec son accord
 * explicite — c'est un autre mécanisme, et il n'existe pas encore.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Stock {

	const OPTION = 'felar_connect_stock';
	const ACTION = 'felar_connect_stock_passage';
	const GROUPE = 'felar-connect';

	/** Dix noms par cas : de quoi reconnaître la situation, pas de quoi la dérouler. */
	const EXEMPLES_MAX = 10;

	/** @var Felar_Reglages */
	private $reglages;

	/** @var Felar_Propagation */
	private $propagation;

	public function __construct( Felar_Reglages $reglages, Felar_Propagation $propagation ) {
		$this->reglages    = $reglages;
		$this->propagation = $propagation;
	}

	/** Branche le traitement des passages. */
	public function brancher() {
		add_action( self::ACTION, array( $this, 'passer' ) );
	}

	/**
	 * Met la synchronisation en route.
	 *
	 * Volontairement <b>pas</b> automatique après un import : le premier passage
	 * écrit des quantités dans la boutique du marchand. C'est à lui de le décider,
	 * en sachant que c'est Felar qui tiendra le stock à partir de là.
	 */
	public function activer() {
		if ( ! $this->ordonnanceur_disponible() ) {
			return array(
				'actif'   => false,
				'message' => 'Action Scheduler n\'est pas disponible. Il est livré avec WooCommerce : vérifiez que WooCommerce est actif et à jour.',
			);
		}

		$this->desactiver( false );
		as_schedule_recurring_action(
			time() + 30,
			Felar_Contrat::FLUX_INTERVALLE,
			self::ACTION,
			array(),
			self::GROUPE
		);

		$etat          = $this->etat_brut();
		$etat['actif'] = true;
		$this->enregistrer( $etat );
		Felar_Journal::noter( 'Synchronisation du stock activée.' );

		return array(
			'actif'   => true,
			'message' => 'Synchronisation activée.',
		);
	}

	/**
	 * Arrête la synchronisation.
	 *
	 * @param bool $retenir Enregistrer l'arrêt dans l'état — faux lors d'une simple
	 *                      remise à neuf de la programmation.
	 */
	public function desactiver( $retenir = true ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array(), self::GROUPE );
		}
		if ( $retenir ) {
			$etat          = $this->etat_brut();
			$etat['actif'] = false;
			$this->enregistrer( $etat );
			Felar_Journal::noter( 'Synchronisation du stock arrêtée.' );
		}
	}

	/**
	 * Un passage : lit le flux page par page, et applique.
	 *
	 * @return array L'état, tel que l'écran l'affiche.
	 */
	public function passer() {
		$etat = $this->etat_brut();

		if ( ! $this->reglages->branchee() ) {
			return $this->clore( $etat, 'erreur', 'Aucune clé de connecteur n\'est enregistrée.' );
		}

		$client = $this->reglages->client();
		$etat   = $this->remettre_les_compteurs( $etat );

		for ( $page = 0; $page < Felar_Contrat::FLUX_PAGES_PAR_PASSAGE; $page++ ) {
			$resultat = $client->lire_les_produits( $etat['since'], $etat['curseur'] );

			if ( 'attendre' === $resultat['issue'] ) {
				// La limite de débit n'est pas un échec : on reprendra au même
				// curseur, après le délai que Felar a demandé.
				$this->enregistrer( $etat );
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action(
						time() + (int) $resultat['delai'],
						self::ACTION,
						array(),
						self::GROUPE
					);
				}
				return $this->vue( $etat );
			}

			if ( 'ok' !== $resultat['issue'] ) {
				// Réseau ou refus : on garde le curseur, et le passage suivant
				// reprendra là. Une clé révoquée se corrige en la remplaçant, et la
				// synchronisation repart d'elle-même — c'est pourquoi on ne la coupe
				// pas ici.
				return $this->clore( $etat, 'erreur', $resultat['message'] );
			}

			$donnees = $resultat['donnees'];

			// Le `syncedAt` retenu est celui de la PREMIÈRE page du passage, pas de
			// la dernière : il a été figé avant la lecture, donc il ne peut pas
			// sauter une modification survenue pendant la pagination. Le prix à
			// payer est de relire quelques lignes au passage suivant, et écrire est
			// idempotent.
			if ( '' === $etat['fige'] && ! empty( $donnees['syncedAt'] ) ) {
				$etat['fige'] = (string) $donnees['syncedAt'];
			}

			$etat = $this->appliquer( $etat, isset( $donnees['products'] ) ? (array) $donnees['products'] : array() );

			$suite = isset( $donnees['nextCursor'] ) ? trim( (string) $donnees['nextCursor'] ) : '';
			if ( '' === $suite ) {
				// Le flux est épuisé : c'est le bon moment pour vider la file des
				// modifications que le marchand a acceptées. Un seul rythme pour les
				// deux sens, et un interrupteur de moins à comprendre.
				$this->propagation->passer();
				// Le flux est épuisé : le curseur retombe, et c'est l'instant figé
				// qui devient le point de départ du prochain passage.
				$etat['since']   = '' !== $etat['fige'] ? $etat['fige'] : $etat['since'];
				$etat['curseur'] = '';
				$etat['fige']    = '';
				return $this->clore( $etat, 'ok', '' );
			}

			$etat['curseur'] = $suite;
		}

		// Beaucoup de pages d'un coup : on rend la main et on reprend tout de suite
		// après, plutôt que de tenir une tâche de fond pendant plusieurs minutes.
		$this->enregistrer( $etat );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, array(), self::GROUPE );
		}
		return $this->vue( $etat );
	}

	/**
	 * Applique une page du flux.
	 *
	 * @param array $etat   L'état courant.
	 * @param array $lignes Les entrées de `products`.
	 * @return array
	 */
	private function appliquer( array $etat, array $lignes ) {
		foreach ( $lignes as $ligne ) {
			$etat['compteurs']['lus']++;

			$identifiant = isset( $ligne['externalId'] ) ? (int) $ligne['externalId'] : 0;
			$produit     = $identifiant > 0 ? wc_get_product( $identifiant ) : null;

			$verdict = Felar_Stock_Regles::decider( $ligne, $this->faits( $produit, $identifiant ) );
			$nom     = $this->nom( $produit, $ligne );

			if ( null !== $verdict['note'] ) {
				$etat = $this->compter_un_cas( $etat, $verdict['note'], $nom );
			}

			if ( 'ecrire' === $verdict['action'] ) {
				// `wc_update_product_stock` avec l'opération « set » : c'est le geste
				// de WooCommerce lui-même, celui qui tient à jour la table de
				// recherche et l'état « en stock / en rupture ». Poser la valeur à la
				// main laisserait les deux désaccordés.
				wc_update_product_stock( $produit, $verdict['quantite'], 'set' );
				$etat['compteurs']['ecrits']++;
			} elseif ( 'inchange' === $verdict['action'] ) {
				$etat['compteurs']['inchanges']++;
			} else {
				$etat['compteurs']['ignores']++;
			}
		}

		return $etat;
	}

	/**
	 * Ce que WooCommerce dit de l'article visé.
	 *
	 * <h3>Le piège de `managing_stock()`, encore</h3>
	 * Sur une variation, il peut rendre la chaîne `'parent'` — « mon stock est
	 * celui du produit parent ». La comparaison est donc stricte : une variation qui
	 * a cessé de suivre son propre stock depuis l'import doit être signalée, pas
	 * recevoir une quantité qui n'ira nulle part.
	 */
	private function faits( $produit, $identifiant ) {
		if ( ! $produit ) {
			return array( 'existe' => false );
		}

		return array(
			'existe'        => true,
			'felar_id'      => Felar_Liens::felar_id( $identifiant ),
			'suit_le_stock' => true === $produit->managing_stock(),
			'stock'         => $produit->get_stock_quantity(),
			'nom'           => $produit->get_name(),
		);
	}

	/** Le nom à montrer au marchand : le sien d'abord, celui de Felar à défaut. */
	private function nom( $produit, array $ligne ) {
		if ( $produit ) {
			return $produit->get_name();
		}
		if ( ! empty( $ligne['label'] ) ) {
			return (string) $ligne['label'];
		}
		return 'Article ' . ( isset( $ligne['externalId'] ) ? $ligne['externalId'] : '?' );
	}

	/** Incrémente un cas, en gardant quelques noms en exemple. */
	private function compter_un_cas( array $etat, $code, $nom ) {
		if ( ! isset( $etat['cas'][ $code ] ) ) {
			$etat['cas'][ $code ] = array(
				'nombre'   => 0,
				'exemples' => array(),
			);
		}
		$etat['cas'][ $code ]['nombre']++;
		if ( count( $etat['cas'][ $code ]['exemples'] ) < self::EXEMPLES_MAX ) {
			$etat['cas'][ $code ]['exemples'][] = $nom;
		}
		return $etat;
	}

	/**
	 * Remet les compteurs à zéro au début d'un passage.
	 *
	 * Ils décrivent le dernier passage, pas le cumul depuis l'installation : un
	 * total qui enfle depuis trois mois ne dit rien de ce qui vient de se produire.
	 * On ne les remet pas quand on reprend une pagination en cours, sinon un gros
	 * catalogue n'afficherait jamais que sa dernière tranche.
	 */
	private function remettre_les_compteurs( array $etat ) {
		if ( '' !== $etat['curseur'] ) {
			return $etat;
		}
		$etat['compteurs'] = array(
			'lus'       => 0,
			'ecrits'    => 0,
			'inchanges' => 0,
			'ignores'   => 0,
		);
		$etat['cas'] = array();
		return $etat;
	}

	/** Clôt le passage, et laisse une trace. */
	private function clore( array $etat, $verdict, $message ) {
		$etat['dernier']  = time();
		$etat['verdict']  = $verdict;
		$etat['message']  = $message;
		$this->enregistrer( $etat );

		if ( 'erreur' === $verdict ) {
			Felar_Journal::noter( 'Synchronisation du stock : ' . $message, 'error' );
		} elseif ( $etat['compteurs']['ecrits'] > 0 ) {
			Felar_Journal::retenir(
				'stock',
				'termine',
				$etat['compteurs']['ecrits'] . ' quantité(s) mise(s) à jour depuis Felar.',
				$etat['compteurs']
			);
		}

		return $this->vue( $etat );
	}

	/** L'état brut, complété par ses valeurs par défaut. */
	public function etat_brut() {
		$defauts = array(
			'actif'     => false,
			'since'     => '',
			'curseur'   => '',
			'fige'      => '',
			'dernier'   => 0,
			'verdict'   => '',
			'message'   => '',
			'compteurs' => array(
				'lus'       => 0,
				'ecrits'    => 0,
				'inchanges' => 0,
				'ignores'   => 0,
			),
			'cas'       => array(),
		);

		$etat = get_option( self::OPTION, array() );
		return array_merge( $defauts, is_array( $etat ) ? $etat : array() );
	}

	/** Ce que l'écran affiche. */
	public function etat() {
		return $this->vue( $this->etat_brut() );
	}

	private function vue( array $etat ) {
		$cas = array();
		foreach ( $etat['cas'] as $code => $detail ) {
			$cas[] = array(
				'code'     => $code,
				'nombre'   => $detail['nombre'],
				'exemples' => $detail['exemples'],
				'phrase'   => Felar_Stock_Regles::phrase( $code ),
			);
		}
		usort(
			$cas,
			function ( $a, $b ) {
				return $b['nombre'] - $a['nombre'];
			}
		);

		return array(
			'actif'      => (bool) $etat['actif'],
			'depuis'     => (string) $etat['since'],
			'en_cours'   => '' !== $etat['curseur'],
			'dernier'    => (int) $etat['dernier'],
			'verdict'    => (string) $etat['verdict'],
			'message'    => (string) $etat['message'],
			'compteurs'  => $etat['compteurs'],
			'cas'        => $cas,
			'prochain'   => $this->prochain_passage(),
			'intervalle' => Felar_Contrat::FLUX_INTERVALLE,
			// Les deux sens voyagent au même rythme : un interrupteur de moins à
			// comprendre, et une seule date à regarder quand on doute.
			'propagation' => $this->propagation->etat(),
		);
	}

	/**
	 * Quand la prochaine lecture est prévue, ou 0.
	 *
	 * Un « actif » sans passage prévu est un piège silencieux : c'est le signe que
	 * la boucle interne de WordPress ne part pas, et l'écran doit pouvoir le dire.
	 */
	private function prochain_passage() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}
		$quand = as_next_scheduled_action( self::ACTION, array(), self::GROUPE );
		return is_numeric( $quand ) ? (int) $quand : 0;
	}

	/** Vrai si Action Scheduler est là. */
	public function ordonnanceur_disponible() {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	/**
	 * Oublie le point de reprise, pour tout relire au passage suivant.
	 *
	 * Utile après une reprise de sauvegarde côté Felar, ou quand le marchand doute
	 * de ce qui est affiché. Sans conséquence : écrire est idempotent.
	 */
	public function tout_relire() {
		$etat            = $this->etat_brut();
		$etat['since']   = '';
		$etat['curseur'] = '';
		$etat['fige']    = '';
		$this->enregistrer( $etat );
	}

	private function enregistrer( array $etat ) {
		update_option( self::OPTION, $etat, false );
	}

	/** Oublie tout, à la désinstallation. */
	public static function oublier() {
		delete_option( self::OPTION );
	}
}
