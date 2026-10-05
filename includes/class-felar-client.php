<?php
/**
 * Le client de l'API Connect.
 *
 * <h2>Trois issues, pas deux</h2>
 * Un appel ne « réussit » pas ou « échoue » pas : il réussit, il est <b>à
 * rejouer</b>, ou il est <b>fatal</b>. Confondre les deux derniers donne l'un des
 * deux défauts classiques d'un connecteur : soit il abandonne un import entier
 * parce que le serveur a hoqueté une seconde, soit il rejoue indéfiniment un
 * appel qu'une clé révoquée condamnera toujours.
 *
 * | Issue | Ce qui la produit | Ce que fait l'appelant |
 * |---|---|---|
 * | `ok` | `200` | il lit le rapport ligne par ligne |
 * | `attendre` | `429`, avec `Retry-After` | il repasse plus tard, au même endroit |
 * | `reseau` | coupure, DNS, délai dépassé, `5xx` | il réessaie, quelques fois |
 * | `fatal` | `401`, `403`, `400` | il s'arrête et le dit au marchand |
 *
 * <h2>Rejouer est sans danger</h2>
 * Un appel perdu après que le serveur a écrit n'est pas un problème : l'envoi de
 * catalogue est identifié par `externalId`, donc le rejouer met à jour au lieu de
 * créer un doublon. C'est ce qui permet de réessayer sans rien vérifier d'abord.
 *
 * <h2>La clé ne sort jamais d'ici</h2>
 * Elle part dans un en-tête, et aucun message rendu par cette classe ne la
 * contient. Une clé recopiée dans un journal de débogage est une clé qu'il
 * faudra révoquer.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Client {

	/** Une lecture est courte : si elle traîne, c'est que quelque chose est cassé. */
	const DELAI_LECTURE = 20;

	/** Un envoi de catalogue sans image. */
	const DELAI_ECRITURE = 60;

	/**
	 * Un envoi dont Felar doit télécharger les images.
	 *
	 * Felar va chercher chaque visuel, le réduit et le range : cent images, c'est
	 * plus d'une minute de travail légitime. Le délai de cinq secondes par défaut
	 * de `wp_remote_post` produirait ici un abandon systématique sur les gros
	 * catalogues — et un abandon sur un appel qui, lui, aboutit côté serveur.
	 */
	const DELAI_ECRITURE_IMAGES = 150;

	/** @var Felar_Transport */
	private $transport;

	/** @var string */
	private $base;

	/** @var string */
	private $cle;

	/**
	 * @param Felar_Transport $transport Le transport.
	 * @param string          $base      L'adresse du serveur, sans chemin.
	 * @param string          $cle       La clé de connecteur.
	 */
	public function __construct( Felar_Transport $transport, $base, $cle ) {
		$this->transport = $transport;
		$this->base      = rtrim( trim( (string) $base ), '/' );
		$this->cle       = trim( (string) $cle );
	}

	/**
	 * L'appairage.
	 *
	 * Répond même quand le module n'est pas actif sur l'offre du marchand : c'est
	 * ce qui permet de lui dire « votre clé est bonne, c'est votre abonnement qui
	 * bloque » au lieu de lui montrer un code qu'il ne saura pas traduire.
	 *
	 * @return array
	 */
	public function ping() {
		$reponse = $this->appeler( 'GET', '/ping', null, self::DELAI_LECTURE );
		return $this->interpreter( $reponse );
	}

	/**
	 * Envoie un paquet de lignes de catalogue.
	 *
	 * @param array  $lignes Cent au plus ; au-delà le serveur refuse le paquet.
	 * @param string $source La plateforme d'origine.
	 * @return array
	 */
	public function envoyer_produits( array $lignes, $source = 'woocommerce' ) {
		if ( empty( $lignes ) ) {
			return array(
				'issue'   => 'ok',
				'donnees' => array(
					'summary' => array(
						'received' => 0,
						'created'  => 0,
						'updated'  => 0,
						'rejected' => 0,
					),
					'results' => array(),
				),
			);
		}

		$avec_images = false;
		foreach ( $lignes as $ligne ) {
			if ( ! empty( $ligne['imageUrl'] ) ) {
				$avec_images = true;
				break;
			}
		}

		$corps = wp_json_encode(
			array(
				'source'   => $source,
				'products' => array_values( $lignes ),
			)
		);

		$reponse = $this->appeler(
			'POST',
			'/products/batch',
			$corps,
			$avec_images ? self::DELAI_ECRITURE_IMAGES : self::DELAI_ECRITURE
		);

		return $this->interpreter( $reponse );
	}

	/**
	 * Lit le flux de stock.
	 *
	 * @param string $depuis Le `syncedAt` du passage précédent, jamais l'horloge du
	 *                       site : une horloge de deux minutes en avance ferait
	 *                       disparaître deux minutes de modifications à chaque tour,
	 *                       définitivement.
	 * @param string $curseur Le `nextCursor` de la page précédente, pour continuer.
	 * @param int    $limite  1 à 500.
	 * @return array
	 */
	public function lire_les_produits( $depuis = '', $curseur = '', $limite = Felar_Contrat::FLUX_PAR_PAGE ) {
		$parametres = array( 'limit' => max( 1, min( 500, (int) $limite ) ) );

		// Le curseur l'emporte : il porte déjà la position exacte dans la page
		// suivante, et y ajouter un `since` restreindrait une seconde fois un flux
		// déjà restreint.
		if ( '' !== trim( (string) $curseur ) ) {
			$parametres['cursor'] = trim( (string) $curseur );
		} elseif ( '' !== trim( (string) $depuis ) ) {
			$parametres['since'] = trim( (string) $depuis );
		}

		$reponse = $this->appeler(
			'GET',
			'/products?' . http_build_query( $parametres ),
			null,
			self::DELAI_LECTURE
		);

		return $this->interpreter( $reponse );
	}

	/**
	 * Envoie une commande.
	 *
	 * Rejouer est <b>sans danger</b> : Felar reconnaît le couple (compte, source,
	 * identifiant externe) et renvoie la commande déjà enregistrée avec
	 * `duplicate: true`, jamais une jumelle et jamais une erreur. Un second envoi
	 * n'est pas une faute, c'est le prix d'une livraison garantie.
	 *
	 * @param array $commande Le corps, déjà conforme au contrat.
	 * @return array
	 */
	public function envoyer_commande( array $commande ) {
		return $this->interpreter(
			$this->appeler( 'POST', '/orders', wp_json_encode( $commande ), self::DELAI_ECRITURE )
		);
	}

	/**
	 * Annonce la suite d'une commande : réglée, ou annulée.
	 *
	 * @param string $externe L'identifiant de la commande chez le marchand.
	 * @param array  $etat    `status`, et éventuellement `method`, `paidAt`, `amount`.
	 * @return array
	 */
	public function changer_letat( $externe, array $etat ) {
		return $this->interpreter(
			$this->appeler(
				'POST',
				// `?source=` est facultatif — Felar retrouve la commande sur le seul
				// identifiant — mais l'envoyer lève toute ambiguïté le jour où le
				// marchand branche une seconde boutique sur le même compte.
				'/orders/' . rawurlencode( (string) $externe ) . '/status?source=woocommerce',
				wp_json_encode( $etat ),
				self::DELAI_ECRITURE
			)
		);
	}

	/**
	 * Ce que Felar demande de porter sur la boutique.
	 *
	 * Seules les modifications que le marchand a **acceptées** arrivent ici : la
	 * frontière de propriété donne ces champs à sa boutique, et Felar ne les y
	 * porte pas sans son accord.
	 *
	 * @param int $limite Cent par passage suffisent ; le suivant finira.
	 * @return array
	 */
	public function lire_les_modifications( $limite = 100 ) {
		return $this->interpreter(
			$this->appeler(
				'GET',
				'/changes?' . http_build_query(
					array(
						'source' => 'woocommerce',
						'limit'  => max( 1, (int) $limite ),
					)
				),
				null,
				self::DELAI_LECTURE
			)
		);
	}

	/**
	 * Dit à Felar ce que la boutique a réellement posé.
	 *
	 * La valeur renvoyée n'est pas celle qu'il a demandée mais celle qui est
	 * affichée — un prix arrondi, un intitulé coupé. C'est ce qui coupe la boucle
	 * d'écho : sans elle, Felar verrait un écart à sa relecture suivante et
	 * reproposerait la même propagation, indéfiniment.
	 *
	 * @param array $accuses Un accusé par modification.
	 * @return array
	 */
	public function accuser_les_modifications( array $accuses ) {
		if ( empty( $accuses ) ) {
			return array(
				'issue'   => 'ok',
				'code'    => '',
				'message' => '',
				'delai'   => 0,
				'donnees' => array(),
			);
		}

		return $this->interpreter(
			$this->appeler(
				'POST',
				'/changes/ack',
				wp_json_encode( array( 'changes' => array_values( $accuses ) ) ),
				self::DELAI_ECRITURE
			)
		);
	}

	/**
	 * Annonce un remboursement, ligne par ligne.
	 *
	 * <h3>Pourquoi des lignes</h3>
	 * Un remboursement décide de l'argent <b>et</b> de la marchandise. Un montant
	 * seul ne dit pas quel article revient en stock, et Felar refuse de le deviner :
	 * un retour mal attribué fausse un inventaire pour des mois. WooCommerce, lui,
	 * connaît les lignes que le marchand a cochées.
	 *
	 * @param string $externe L'identifiant de la commande chez le marchand.
	 * @param array  $corps   `lines`, et éventuellement `reason`, `restock`, `creditNote`.
	 * @return array
	 */
	public function rembourser( $externe, array $corps ) {
		return $this->interpreter(
			$this->appeler(
				'POST',
				'/orders/' . rawurlencode( (string) $externe ) . '/refund?source=woocommerce',
				wp_json_encode( $corps ),
				self::DELAI_ECRITURE
			)
		);
	}

	/** L'adresse complète d'un chemin du contrat. */
	public function adresse( $chemin ) {
		return $this->base . Felar_Contrat::CHEMIN . $chemin;
	}

	/**
	 * Un appel, avec la clé dans l'en-tête et jamais dans l'adresse.
	 *
	 * @param string      $methode 'GET' ou 'POST'.
	 * @param string      $chemin  Le chemin, relatif à la base du contrat.
	 * @param string|null $corps   Le corps JSON.
	 * @param int         $delai   Le délai d'attente.
	 * @return Felar_Reponse
	 */
	private function appeler( $methode, $chemin, $corps, $delai ) {
		$entetes = array(
			Felar_Contrat::ENTETE_CLE => $this->cle,
			'Accept'                  => 'application/json',
		);
		if ( null !== $corps ) {
			$entetes['Content-Type'] = 'application/json';
		}

		return $this->transport->appeler( $methode, $this->adresse( $chemin ), $entetes, $corps, $delai );
	}

	/**
	 * Traduit une réponse en issue.
	 *
	 * @param Felar_Reponse $reponse La réponse.
	 * @return array{issue: string, code: string, message: string, delai: int, donnees: array}
	 */
	private function interpreter( Felar_Reponse $reponse ) {
		if ( $reponse->injoignable() ) {
			return $this->issue(
				'reseau',
				'INJOIGNABLE',
				'Felar n\'a pas répondu : ' . (string) $reponse->message_de_panne()
			);
		}

		$statut  = $reponse->statut();
		$donnees = $reponse->json();

		if ( 200 === $statut || 201 === $statut ) {
			return array(
				'issue'   => 'ok',
				'code'    => '',
				'message' => '',
				'delai'   => 0,
				'donnees' => $donnees,
			);
		}

		$code    = isset( $donnees['code'] ) ? (string) $donnees['code'] : '';
		$message = isset( $donnees['message'] ) ? (string) $donnees['message'] : '';

		if ( 429 === $statut ) {
			return array(
				'issue'   => 'attendre',
				'code'    => '' !== $code ? $code : 'RATE_LIMITED',
				'message' => '' !== $message ? $message : 'Trop d\'appels : Felar demande de patienter.',
				'delai'   => $this->patience( $reponse->entete( 'retry-after' ) ),
				'donnees' => $donnees,
			);
		}

		if ( $statut >= 500 ) {
			return $this->issue(
				'reseau',
				'' !== $code ? $code : 'SERVER_ERROR',
				'' !== $message ? $message : 'Felar a répondu par une erreur interne.'
			);
		}

		return $this->issue( 'fatal', '' !== $code ? $code : 'HTTP_' . $statut, $this->phrase_fatale( $statut, $code, $message ) );
	}

	/**
	 * La phrase qu'on montre au marchand quand l'appel est condamné.
	 *
	 * Le `message` du serveur est déjà en français et déjà utile ; on ne le
	 * remplace que là où le marchand a besoin d'un geste plutôt que d'un constat.
	 */
	private function phrase_fatale( $statut, $code, $message ) {
		if ( 'MISSING_API_KEY' === $code || 'INVALID_API_KEY' === $code ) {
			return 'Felar refuse la clé. Recopiez-la depuis Felar → Réglages → Mon site, ou générez-en une nouvelle si celle-ci a été révoquée.';
		}
		if ( 'MODULE_REQUIRED' === $code ) {
			return 'Votre clé est reconnue, mais votre abonnement Felar ne comprend pas le module Boutique. Activez-le dans Felar, puis relancez.';
		}
		if ( 'BATCH_TOO_LARGE' === $code ) {
			return 'Felar refuse un envoi de plus de cent lignes. Signalez-le au support : c\'est un défaut de l\'extension, pas de votre catalogue.';
		}
		if ( '' !== $message ) {
			return $message;
		}
		return 'Felar a refusé l\'appel (code ' . (int) $statut . ').';
	}

	/**
	 * Le délai demandé par `Retry-After`, borné.
	 *
	 * Sans borne haute, un en-tête mal formé — ou un intermédiaire trop zélé —
	 * endormirait la synchronisation pour des heures. Sans borne basse, on
	 * repartirait aussitôt et on se ferait refuser encore.
	 */
	private function patience( $entete ) {
		$secondes = is_numeric( $entete ) ? (int) $entete : 60;
		if ( $secondes < 5 ) {
			$secondes = 5;
		}
		if ( $secondes > 600 ) {
			$secondes = 600;
		}
		return $secondes;
	}

	/** Une issue sans données. */
	private function issue( $issue, $code, $message ) {
		return array(
			'issue'   => $issue,
			'code'    => $code,
			'message' => $message,
			'delai'   => 0,
			'donnees' => array(),
		);
	}

	/**
	 * La clé, masquée, pour un affichage.
	 *
	 * On garde le préfixe — il dit le mode, et c'est la première cause de
	 * confusion — et les quatre derniers caractères, qui suffisent au marchand
	 * pour reconnaître laquelle de ses clés est en place.
	 */
	public static function masquer( $cle ) {
		$cle = trim( (string) $cle );
		if ( '' === $cle ) {
			return '';
		}
		$fin = substr( $cle, -4 );
		$mode = Felar_Contrat::mode_de_la_cle( $cle );
		$prefixe = 'test' === $mode ? Felar_Contrat::PREFIXE_TEST : ( 'live' === $mode ? Felar_Contrat::PREFIXE_LIVE : '' );
		return $prefixe . '…' . $fin;
	}
}
