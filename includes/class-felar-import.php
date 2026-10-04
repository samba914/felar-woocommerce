<?php
/**
 * L'import du catalogue, par Action Scheduler.
 *
 * <h2>Pourquoi pas une boucle</h2>
 * Un import de quatre mille articles demande quarante appels réseau à Felar, dont
 * certains téléchargent des images. Une boucle dans une requête d'administration
 * meurt à la trentième seconde et laisse un catalogue à moitié monté, sans que
 * personne sache où il s'est arrêté. Action Scheduler est livré avec WooCommerce
 * exactement pour cela : chaque tranche est une tâche, elle reprend où la
 * précédente s'est arrêtée, et une coupure ne perd qu'une tranche.
 *
 * <h2>Reprendre est sans danger</h2>
 * L'envoi est identifié par l'identifiant WooCommerce du produit : rejouer une
 * tranche met à jour au lieu de créer un doublon. C'est ce qui permet de
 * réessayer après une coupure sans rien vérifier d'abord.
 *
 * <h2>Le stock ne monte qu'une fois</h2>
 * À la création, Felar accepte le stock — c'est la reprise de l'existant. Ensuite
 * il l'ignore et le dit par un avertissement <code>STOCK_IGNORED</code>, parce que
 * le stock bouge à la caisse, à la réception et à l'inventaire, et que WooCommerce
 * ne voit rien de tout cela. L'extension compte ces avertissements et les montre :
 * un connecteur qui envoie du stock à chaque passage travaille pour rien.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Import {

	const OPTION = 'felar_connect_import';
	const ACTION = 'felar_connect_import_lot';
	const GROUPE = 'felar-connect';

	/** Trois tentatives sur une panne passagère, puis on s'arrête et on le dit. */
	const TENTATIVES_MAX = 3;

	/** L'attente entre deux tentatives, en secondes. */
	const ATTENTES = array( 30, 120, 300 );

	/** @var Felar_Reglages */
	private $reglages;

	/** @var Felar_Lecteur */
	private $lecteur;

	public function __construct( Felar_Reglages $reglages, Felar_Lecteur $lecteur ) {
		$this->reglages = $reglages;
		$this->lecteur  = $lecteur;
	}

	/** Branche le traitement des tranches. */
	public function brancher() {
		add_action( self::ACTION, array( $this, 'traiter_un_lot' ), 10, 1 );
	}

	/**
	 * Lance un import.
	 *
	 * @param int[] $identifiants Les produits à parcourir, dans l'ordre.
	 * @param array $refusees     Les UGS en doublon, à ne pas envoyer.
	 * @return array{lance: bool, message: string}
	 */
	public function demarrer( array $identifiants, array $refusees ) {
		if ( ! $this->reglages->branchee() ) {
			return array(
				'lance'   => false,
				'message' => 'Aucune clé de connecteur n\'est enregistrée.',
			);
		}
		if ( empty( $identifiants ) ) {
			return array(
				'lance'   => false,
				'message' => 'Aucun produit à envoyer. Lancez d\'abord l\'analyse du catalogue.',
			);
		}
		if ( ! $this->ordonnanceur_disponible() ) {
			return array(
				'lance'   => false,
				'message' => 'Action Scheduler n\'est pas disponible. Il est livré avec WooCommerce : vérifiez que WooCommerce est bien actif et à jour.',
			);
		}

		$en_cours = $this->etat_brut();
		if ( null !== $en_cours && 'en_cours' === $en_cours['statut'] ) {
			return array(
				'lance'   => false,
				'message' => 'Un import est déjà en cours.',
			);
		}

		$course = 'imp_' . wp_generate_password( 10, false, false );

		$this->enregistrer(
			array(
				'course'       => $course,
				'statut'       => 'en_cours',
				'demarre'      => time(),
				'termine_le'   => 0,
				'essai'        => $this->reglages->en_essai(),
				'ids'          => array_values( array_map( 'intval', $identifiants ) ),
				'index'        => 0,
				'sous_index'   => 0,
				'refusees'     => $refusees,
				'tentatives'   => 0,
				'compteurs'    => array(
					'envoyees'  => 0,
					'crees'     => 0,
					'majs'      => 0,
					'refusees'  => 0,
					'ecartes'   => 0,
					'doublons'  => 0,
					'appels'    => 0,
				),
				'refus'        => array(),
				'refus_total'  => 0,
				'avertissements' => array(),
				'message'      => '',
			)
		);

		as_enqueue_async_action( self::ACTION, array( $course ), self::GROUPE );

		Felar_Journal::noter( 'Import lancé : ' . count( $identifiants ) . ' produits à parcourir.' );

		return array(
			'lance'   => true,
			'message' => 'Import lancé.',
		);
	}

	/**
	 * Traite une tranche, puis programme la suivante.
	 *
	 * @param string $course L'identifiant de la course, pour ne pas faire avancer
	 *                       un import qu'on vient d'arrêter.
	 */
	public function traiter_un_lot( $course ) {
		$etat = $this->etat_brut();
		if ( null === $etat || $etat['course'] !== $course || 'en_cours' !== $etat['statut'] ) {
			// Import arrêté, ou tâche en retard d'une course : on ne fait rien plutôt
			// que de réveiller un envoi que le marchand a interrompu.
			return;
		}

		$lot = $this->construire_un_lot( $etat );

		if ( empty( $lot['lignes'] ) ) {
			$this->clore( $etat, 'termine', 'Import terminé.' );
			return;
		}

		$resultat = $this->reglages->client()->envoyer_produits( $lot['lignes'] );

		if ( 'attendre' === $resultat['issue'] ) {
			// La limite de débit n'est pas un échec : on repart au même endroit,
			// après le délai que Felar a demandé. L'état n'avance pas.
			$etat['compteurs']['appels']++;
			$this->enregistrer( $etat );
			as_schedule_single_action( time() + (int) $resultat['delai'], self::ACTION, array( $course ), self::GROUPE );
			return;
		}

		if ( 'reseau' === $resultat['issue'] ) {
			$etat['tentatives']++;
			if ( $etat['tentatives'] > self::TENTATIVES_MAX ) {
				$this->clore( $etat, 'echec', $resultat['message'] );
				return;
			}
			$attente = self::ATTENTES[ min( $etat['tentatives'] - 1, count( self::ATTENTES ) - 1 ) ];
			$etat['message'] = $resultat['message'] . ' Nouvelle tentative dans ' . $attente . ' secondes.';
			$this->enregistrer( $etat );
			as_schedule_single_action( time() + $attente, self::ACTION, array( $course ), self::GROUPE );
			return;
		}

		if ( 'fatal' === $resultat['issue'] ) {
			$this->clore( $etat, 'echec', $resultat['message'] );
			return;
		}

		// Appel réussi : on avance, et la tentative repart de zéro.
		$etat['tentatives'] = 0;
		$etat['message']    = '';
		$etat['index']      = $lot['index'];
		$etat['sous_index'] = $lot['sous_index'];
		$etat['compteurs']['appels']++;
		$etat['compteurs']['ecartes']  += $lot['ecartes'];
		$etat['compteurs']['doublons'] += $lot['doublons'];

		$etat = $this->appliquer( $etat, $resultat['donnees'], $lot['sans_reference'] );

		$fini = $etat['index'] >= count( $etat['ids'] );
		$this->enregistrer( $etat );

		if ( $fini ) {
			$this->clore( $etat, 'termine', 'Import terminé.' );
			return;
		}

		as_enqueue_async_action( self::ACTION, array( $course ), self::GROUPE );
	}

	/**
	 * Construit la prochaine tranche de lignes.
	 *
	 * Un produit variable peut à lui seul dépasser les cent lignes d'un appel :
	 * `sous_index` retient alors où on s'est arrêté <b>dans</b> le produit. Couper
	 * un modèle en deux appels est sans conséquence — le modèle est reconnu par son
	 * identifiant, pas par le fait d'arriver d'un seul bloc.
	 *
	 * @param array $etat L'état courant.
	 * @return array
	 */
	private function construire_un_lot( array $etat ) {
		$convertisseur  = $this->reglages->convertisseur();
		$total          = count( $etat['ids'] );
		$index          = (int) $etat['index'];
		$sous           = (int) $etat['sous_index'];
		$lignes         = array();
		$sans_reference = array();
		$ecartes        = 0;
		$doublons       = 0;

		while ( $index < $total && count( $lignes ) < Felar_Contrat::LIGNES_PAR_APPEL ) {
			$brut = $this->lecteur->lire( $etat['ids'][ $index ] );
			if ( null === $brut ) {
				$index++;
				$sous = 0;
				continue;
			}

			$conversion = $convertisseur->convertir( $brut );
			if ( 'ecarte' === $conversion['mode'] ) {
				$ecartes++;
				$index++;
				$sous = 0;
				continue;
			}

			$candidates = $conversion['lignes'];
			$nombre     = count( $candidates );

			while ( $sous < $nombre && count( $lignes ) < Felar_Contrat::LIGNES_PAR_APPEL ) {
				$ligne = $candidates[ $sous ];
				$sous++;

				$reference = isset( $ligne['reference'] ) ? $ligne['reference'] : '';
				if ( '' !== $reference && isset( $etat['refusees'][ Felar_Rapport::normaliser( $reference ) ] ) ) {
					// UGS portée par plusieurs articles : l'envoyer ferait refuser la
					// seconde ligne par Felar, et le marchand verrait un refus là où
					// le rapport annonçait une exclusion.
					$doublons++;
					continue;
				}

				if ( '' === $reference ) {
					$sans_reference[ (string) $ligne['externalId'] ] = true;
				}
				$lignes[] = $ligne;
			}

			if ( $sous >= $nombre ) {
				$index++;
				$sous = 0;
			}
		}

		return array(
			'lignes'         => $lignes,
			'index'          => $index,
			'sous_index'     => $sous,
			'ecartes'        => $ecartes,
			'doublons'       => $doublons,
			'sans_reference' => $sans_reference,
		);
	}

	/**
	 * Applique le rapport d'un envoi : liens retenus, refus gardés, avertissements
	 * comptés.
	 *
	 * @param array $etat           L'état courant.
	 * @param array $reponse        Le corps rendu par Felar.
	 * @param array $sans_reference Les lignes envoyées sans UGS.
	 * @return array
	 */
	private function appliquer( array $etat, array $reponse, array $sans_reference ) {
		$lignes = isset( $reponse['results'] ) ? (array) $reponse['results'] : array();

		foreach ( $lignes as $ligne ) {
			$externe = isset( $ligne['externalId'] ) ? (string) $ligne['externalId'] : '';
			$statut  = isset( $ligne['status'] ) ? (string) $ligne['status'] : '';

			if ( 'rejected' === $statut ) {
				$etat['compteurs']['refusees']++;
				$etat['refus_total']++;
				if ( count( $etat['refus'] ) < 50 ) {
					$etat['refus'][] = array(
						'produit' => $externe,
						'code'    => isset( $ligne['error']['code'] ) ? (string) $ligne['error']['code'] : '',
						'message' => isset( $ligne['error']['message'] ) ? (string) $ligne['error']['message'] : '',
					);
				}
				continue;
			}

			$etat['compteurs']['envoyees']++;
			if ( 'created' === $statut ) {
				$etat['compteurs']['crees']++;
			} elseif ( 'updated' === $statut ) {
				$etat['compteurs']['majs']++;
			}

			// En mode essai, Felar n'a rien écrit : garder son identifiant ferait
			// croire à un lien qui n'existe pas, et le premier import réel
			// n'écrirait plus rien.
			if ( empty( $etat['essai'] ) ) {
				Felar_Liens::retenir(
					(int) $externe,
					isset( $ligne['felarId'] ) ? (string) $ligne['felarId'] : '',
					isset( $ligne['reference'] ) ? (string) $ligne['reference'] : ''
				);
			}

			foreach ( (array) ( isset( $ligne['warnings'] ) ? $ligne['warnings'] : array() ) as $avertissement ) {
				$code = isset( $avertissement['code'] ) ? (string) $avertissement['code'] : '';
				if ( '' === $code ) {
					continue;
				}
				$etat['avertissements'][ $code ] = isset( $etat['avertissements'][ $code ] )
					? $etat['avertissements'][ $code ] + 1
					: 1;
			}
		}

		return $etat;
	}

	/** Clôt la course, et laisse une trace lisible. */
	private function clore( array $etat, $statut, $message ) {
		$etat['statut']     = $statut;
		$etat['message']    = $message;
		$etat['termine_le'] = time();
		$this->enregistrer( $etat );

		Felar_Journal::retenir(
			'import',
			$statut,
			$message,
			$etat['compteurs']
		);
		Felar_Journal::noter( 'Import ' . $statut . ' : ' . $message, 'echec' === $statut ? 'error' : 'info' );
	}

	/**
	 * Arrête l'import en cours.
	 *
	 * Les tâches déjà programmées sont retirées : sans cela, la suivante
	 * repartirait dans la minute, et le marchand croirait que le bouton ne marche
	 * pas.
	 */
	public function arreter() {
		$etat = $this->etat_brut();
		if ( null === $etat ) {
			return false;
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array( $etat['course'] ), self::GROUPE );
		}
		if ( 'en_cours' === $etat['statut'] ) {
			$this->clore( $etat, 'arrete', 'Import arrêté. Ce qui est déjà monté reste dans Felar.' );
		}
		return true;
	}

	/**
	 * Fait avancer l'import à la main, d'une tranche.
	 *
	 * Filet pour les sites où la boucle interne de WordPress ne part pas — un
	 * `DISABLE_WP_CRON` sans cron système, un hébergeur qui bloque les appels vers
	 * lui-même. Sans ce bouton, l'import resterait à zéro sans rien expliquer.
	 */
	public function pousser() {
		$etat = $this->etat_brut();
		if ( null === $etat || 'en_cours' !== $etat['statut'] ) {
			return false;
		}
		$this->traiter_un_lot( $etat['course'] );
		return true;
	}

	/** L'état brut, ou null. */
	public function etat_brut() {
		$etat = get_option( self::OPTION, null );
		if ( ! is_array( $etat ) || ! isset( $etat['course'] ) ) {
			return null;
		}
		return $etat;
	}

	/**
	 * Ce que l'écran affiche.
	 *
	 * @return array|null
	 */
	public function etat() {
		$etat = $this->etat_brut();
		if ( null === $etat ) {
			return null;
		}

		return array(
			'statut'         => $etat['statut'],
			'essai'          => ! empty( $etat['essai'] ),
			'lus'            => (int) $etat['index'],
			'total'          => count( $etat['ids'] ),
			'compteurs'      => $etat['compteurs'],
			'refus'          => $etat['refus'],
			'refus_total'    => (int) $etat['refus_total'],
			'avertissements' => $etat['avertissements'],
			'message'        => (string) $etat['message'],
			'demarre'        => (int) $etat['demarre'],
			'termine_le'     => (int) $etat['termine_le'],
			'en_attente'     => $this->taches_en_attente(),
		);
	}

	/**
	 * Le nombre de tâches en attente pour ce groupe.
	 *
	 * Un import « en cours » sans aucune tâche en attente est un import bloqué :
	 * c'est la seule façon de distinguer « ça travaille » de « la boucle interne de
	 * WordPress ne part pas », et c'est ce qui justifie le bouton « faire avancer ».
	 */
	private function taches_en_attente() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$taches = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION,
				'group'    => self::GROUPE,
				'status'   => 'pending',
				'per_page' => 5,
			),
			'ids'
		);
		return count( (array) $taches );
	}

	/** Vrai si Action Scheduler est là. */
	public function ordonnanceur_disponible() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );
	}

	private function enregistrer( array $etat ) {
		update_option( self::OPTION, $etat, false );
	}

	/** Oublie l'import. */
	public static function oublier() {
		delete_option( self::OPTION );
	}
}
