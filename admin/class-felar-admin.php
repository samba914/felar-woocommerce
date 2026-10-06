<?php
/**
 * L'écran d'administration.
 *
 * <h2>Trois gestes, dans l'ordre</h2>
 * Brancher la clé, <b>lire le rapport</b>, lancer l'import. Le rapport n'est pas
 * une étape décorative : un import de catalogue qu'on ne peut pas relire avant est
 * un import qu'on annule après, et annuler veut dire supprimer quatre cents fiches
 * à la main.
 *
 * <h2>Ce qui est vérifié à chaque action</h2>
 * La capacité `manage_woocommerce` et un jeton à usage unique. Les deux, toujours :
 * une action d'administration qui se contente du premier se déclenche depuis
 * n'importe quel site où l'administrateur est connecté ailleurs.
 *
 * <h2>La clé ne repart jamais vers le navigateur</h2>
 * Le champ est toujours rendu vide, et une soumission vide conserve la clé en
 * place. Elle n'apparaît donc ni dans le code source de la page, ni dans le cache
 * du navigateur, ni dans un formulaire réenvoyé par mégarde.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

final class Felar_Admin {

	const PAGE  = 'felar-connect';
	const JETON = 'felar_connect';

	/** @var Felar_Reglages */
	private $reglages;

	/** @var Felar_Analyse */
	private $analyse;

	/** @var Felar_Import */
	private $import;

	/** @var Felar_References */
	private $references;

	/** @var Felar_Stock */
	private $stock;

	/** @var Felar_Commandes */
	private $commandes;

	public function __construct( Felar_Reglages $reglages, Felar_Analyse $analyse, Felar_Import $import, Felar_References $references, Felar_Stock $stock, Felar_Commandes $commandes ) {
		$this->reglages   = $reglages;
		$this->analyse    = $analyse;
		$this->import     = $import;
		$this->references = $references;
		$this->stock      = $stock;
		$this->commandes  = $commandes;
	}

	/** Branche les écrans et les actions. */
	public function brancher() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_felar_enregistrer', array( $this, 'enregistrer' ) );

		$actions = array(
			'felar_tester'      => 'tester',
			'felar_analyser'    => 'analyser',
			'felar_importer'    => 'importer',
			'felar_etat'        => 'etat',
			'felar_arreter'     => 'arreter',
			'felar_pousser'     => 'pousser',
			'felar_references'  => 'ecrire_les_references',
			'felar_stock'       => 'etat_du_stock',
			'felar_stock_actif' => 'basculer_le_stock',
			'felar_stock_maint' => 'synchroniser_le_stock',
			'felar_stock_tout'  => 'tout_relire_le_stock',
			'felar_cmd'         => 'etat_des_commandes',
			'felar_cmd_actif'   => 'basculer_les_commandes',
			'felar_cmd_reprise' => 'reprendre_les_commandes',
			'felar_cmd_refus'   => 'oublier_les_refus',
		);
		foreach ( $actions as $crochet => $methode ) {
			add_action( 'wp_ajax_' . $crochet, array( $this, $methode ) );
		}
	}

	/** L'entrée de menu, sous WooCommerce. */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Felar Connect',
			'Felar Connect',
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'afficher' )
		);
	}

	/** Les feuilles de style et le script, sur notre écran seulement. */
	public function assets( $page ) {
		if ( 'woocommerce_page_' . self::PAGE !== $page ) {
			return;
		}

		wp_enqueue_style(
			'felar-connect',
			FELAR_CONNECT_URL . 'admin/assets/admin.css',
			array(),
			FELAR_CONNECT_VERSION
		);
		wp_enqueue_script(
			'felar-connect',
			FELAR_CONNECT_URL . 'admin/assets/admin.js',
			array(),
			FELAR_CONNECT_VERSION,
			true
		);
		wp_localize_script(
			'felar-connect',
			'felarConnect',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'jeton' => wp_create_nonce( self::JETON ),
			)
		);
	}

	/** L'écran. */
	public function afficher() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Vous n\'avez pas le droit d\'ouvrir cet écran.' );
		}

		$reglages   = $this->reglages;
		$boutique   = $reglages->boutique();
		$desaccord  = $reglages->desaccord_de_devise();
		$import     = $this->import;
		$analyse    = $this->analyse;
		$references = $this->references;
		$stock      = $this->stock;
		$commandes  = $this->commandes;
		$onglet     = isset( $_GET['onglet'] ) ? sanitize_key( wp_unslash( $_GET['onglet'] ) ) : 'connexion';
		if ( ! in_array( $onglet, array( 'connexion', 'import', 'stock', 'commandes', 'journal' ), true ) ) {
			$onglet = 'connexion';
		}

		include FELAR_CONNECT_DIR . 'admin/views/page.php';
	}

	/**
	 * Enregistre les réglages.
	 *
	 * Un champ de clé laissé vide conserve la clé en place : c'est le cas normal,
	 * puisqu'on ne la réaffiche jamais. La supprimer demande une case à cocher, pour
	 * qu'un enregistrement de routine ne débranche pas la boutique.
	 */
	public function enregistrer() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Vous n\'avez pas le droit de modifier ces réglages.' );
		}
		check_admin_referer( self::JETON );

		$valeurs = array(
			'taux_declare'      => isset( $_POST['taux_declare'] )
				? Felar_Taxes::borner( str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['taux_declare'] ) ) ) )
				: 0,
			'avec_brouillons'   => ! empty( $_POST['avec_brouillons'] ),
			'ecrire_references' => ! empty( $_POST['ecrire_references'] ),
		);

		$serveur = isset( $_POST['serveur'] ) ? esc_url_raw( wp_unslash( $_POST['serveur'] ) ) : '';
		if ( '' !== $serveur ) {
			// Une adresse en clair sur un réseau enverrait la clé en clair. Seule une
			// adresse de bouclage y échappe, et pour une raison concrète : voir
			// Felar_Contrat::adresse_acceptable().
			$valeurs['serveur'] = Felar_Contrat::adresse_acceptable( $serveur )
				? rtrim( $serveur, '/' )
				: Felar_Contrat::BASE_DEFAUT;
		}

		if ( ! empty( $_POST['oublier_cle'] ) ) {
			$valeurs['cle']          = '';
			$valeurs['compte']       = '';
			$valeurs['devise']       = '';
			$valeurs['mode']         = '';
			$valeurs['module_actif'] = false;
			$valeurs['appairage']    = 0;
		} else {
			$cle = isset( $_POST['cle'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['cle'] ) ) ) : '';
			if ( '' !== $cle ) {
				$valeurs['cle'] = $cle;
			}
		}

		$this->reglages->ecrire( $valeurs );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'onglet'      => 'connexion',
					'enregistre'  => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Vérifie la requête d'une action en arrière-plan. */
	private function verrou() {
		check_ajax_referer( self::JETON, 'jeton' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Droits insuffisants.' ), 403 );
		}
	}

	/** « Tester la connexion ». */
	public function tester() {
		$this->verrou();

		if ( ! $this->reglages->branchee() ) {
			wp_send_json_error(
				array(
					'message' => 'Collez d\'abord une clé. Elle commence par « ck_live_ » ou « ck_test_ ».',
				)
			);
		}

		$resultat = $this->reglages->client()->ping();

		if ( 'ok' !== $resultat['issue'] ) {
			wp_send_json_error( array( 'message' => $resultat['message'] ) );
		}

		$this->reglages->retenir_appairage( $resultat['donnees'] );
		$boutique = $this->reglages->boutique();

		wp_send_json_success(
			array(
				'compte'       => (string) $this->reglages->lire( 'compte' ),
				'devise'       => (string) $this->reglages->lire( 'devise' ),
				'mode'         => (string) $this->reglages->lire( 'mode' ),
				'module_actif' => (bool) $this->reglages->lire( 'module_actif' ),
				'devise_woo'   => (string) $boutique['devise'],
				'desaccord'    => $this->reglages->desaccord_de_devise(),
			)
		);
	}

	/** Une tranche d'analyse. `reprise` continue, sinon on repart de zéro. */
	public function analyser() {
		$this->verrou();
		$reprise = ! empty( $_POST['reprise'] );
		wp_send_json_success( $reprise ? $this->analyse->avancer() : $this->analyse->demarrer() );
	}

	/** Lance l'import à partir de la dernière analyse. */
	public function importer() {
		$this->verrou();

		$etat = $this->analyse->etat_brut();
		if ( null === $etat || empty( $etat['termine'] ) ) {
			wp_send_json_error(
				array(
					'message' => 'Lancez d\'abord l\'analyse du catalogue, et laissez-la finir : c\'est elle qui repère les UGS en doublon.',
				)
			);
		}

		$desaccord = $this->reglages->desaccord_de_devise();
		if ( null !== $desaccord ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						'Votre boutique est tenue en %1$s et votre compte Felar en %2$s. Felar ne convertit rien — un taux de change appliqué en silence produirait des factures fausses. Alignez les deux devises avant d\'importer.',
						$desaccord['woo'],
						$desaccord['felar']
					),
				)
			);
		}

		$verdict = $this->import->demarrer(
			$this->analyse->identifiants_analyses(),
			$this->analyse->references_refusees()
		);

		if ( ! $verdict['lance'] ) {
			wp_send_json_error( array( 'message' => $verdict['message'] ) );
		}

		wp_send_json_success( $this->import->etat() );
	}

	/**
	 * L'avancement de l'import.
	 *
	 * Le compte des références en attente n'est fait qu'une fois l'import terminé :
	 * cet écran s'interroge toutes les quatre secondes, et compter à chaque passage
	 * relancerait une requête sur tout le catalogue pour un chiffre qui bouge encore.
	 * L'écran garde alors le dernier connu, ce qui est exactement ce qu'il faut.
	 */
	public function etat() {
		$this->verrou();
		$import = $this->import->etat();

		wp_send_json_success(
			array(
				'import'     => $import,
				'references' => ( null !== $import && 'en_cours' === $import['statut'] )
					? null
					: $this->references->combien_en_attente(),
			)
		);
	}

	/** Arrête l'import. */
	public function arreter() {
		$this->verrou();
		$this->import->arreter();
		wp_send_json_success( $this->import->etat() );
	}

	/** Fait avancer l'import d'une tranche, à la main. */
	public function pousser() {
		$this->verrou();
		$this->import->pousser();
		wp_send_json_success( $this->import->etat() );
	}

	/** Écrit dans WooCommerce une tranche de références fabriquées par Felar. */
	public function ecrire_les_references() {
		$this->verrou();
		wp_send_json_success( $this->references->ecrire_une_tranche() );
	}

	/** L'état de la synchronisation du stock. */
	public function etat_du_stock() {
		$this->verrou();
		wp_send_json_success( $this->stock->etat() );
	}

	/**
	 * Met la synchronisation en route, ou l'arrête.
	 *
	 * Le premier passage écrira des quantités dans la boutique du marchand : c'est
	 * pourquoi rien ne démarre tout seul après un import, et pourquoi ce bouton
	 * existe.
	 */
	public function basculer_le_stock() {
		$this->verrou();

		if ( ! empty( $_POST['actif'] ) ) {
			if ( ! $this->reglages->branchee() ) {
				wp_send_json_error( array( 'message' => "Branchez d'abord une clé dans l'onglet Connexion." ) );
			}
			$verdict = $this->stock->activer();
			if ( empty( $verdict['actif'] ) ) {
				wp_send_json_error( array( 'message' => $verdict['message'] ) );
			}
		} else {
			$this->stock->desactiver();
		}

		wp_send_json_success( $this->stock->etat() );
	}

	/** « Synchroniser maintenant » : un passage tout de suite, sans attendre. */
	public function synchroniser_le_stock() {
		$this->verrou();
		if ( ! $this->reglages->branchee() ) {
			wp_send_json_error( array( 'message' => "Branchez d'abord une clé dans l'onglet Connexion." ) );
		}
		wp_send_json_success( $this->stock->passer() );
	}

	/** Oublie le point de reprise : le passage suivant relit tout. */
	public function tout_relire_le_stock() {
		$this->verrou();
		$this->stock->tout_relire();
		wp_send_json_success( $this->stock->passer() );
	}

	/** L'état de l'envoi des commandes. */
	public function etat_des_commandes() {
		$this->verrou();
		wp_send_json_success( $this->commandes->etat() );
	}

	/** Met l'envoi des commandes en route, ou l'arrête. */
	public function basculer_les_commandes() {
		$this->verrou();
		$actif = ! empty( $_POST['actif'] );
		if ( $actif && ! $this->reglages->branchee() ) {
			wp_send_json_error( array( 'message' => "Branchez d'abord une clé dans l'onglet Connexion." ) );
		}
		$this->commandes->basculer( $actif );
		wp_send_json_success( $this->commandes->etat() );
	}

	/** Reprend les commandes récentes, pour celles passées pendant que c'était éteint. */
	public function reprendre_les_commandes() {
		$this->verrou();
		$combien = $this->commandes->reprendre_les_recentes( 25 );
		$etat    = $this->commandes->etat();
		$etat['reprises'] = $combien;
		wp_send_json_success( $etat );
	}

	/**
	 * Oublie les refus affichés.
	 *
	 * Le marchand vient de corriger ce qui bloquait, et il a besoin d'un écran
	 * propre pour voir si sa correction a pris. Sans ce bouton, un refus passager
	 * restait affiché à vie — et un écran d'alerte qui ne redevient jamais propre
	 * cesse d'être lu.
	 */
	public function oublier_les_refus() {
		$this->verrou();
		$this->commandes->oublier_les_refus();
		wp_send_json_success( $this->commandes->etat() );
	}
}
