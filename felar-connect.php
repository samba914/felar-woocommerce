<?php
/**
 * Plugin Name:       Felar Connect pour WooCommerce
 * Plugin URI:        https://felar-crm.com
 * Description:       Branche votre boutique WooCommerce sur Felar : votre site reste votre vitrine, Felar devient votre arrière-boutique — stock, caisse, commandes, clients, factures.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Felar
 * Author URI:        https://felar-crm.com
 * License:           Proprietary
 * Text Domain:       felar-connect
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

define( 'FELAR_CONNECT_VERSION', '1.1.0' );
define( 'FELAR_CONNECT_FILE', __FILE__ );
define( 'FELAR_CONNECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'FELAR_CONNECT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Charge l'extension, une fois WooCommerce là.
 *
 * <h2>Pourquoi ce garde-fou</h2>
 * Sans WooCommerce, aucune des fonctions employées ici n'existe : l'extension ne
 * produirait pas un message, elle produirait un écran blanc sur toute
 * l'administration du site. Un marchand qui désactive WooCommerce une minute pour
 * dépanner autre chose n'a pas à perdre son site.
 */
function felar_connect_demarrer() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'felar_connect_sans_woocommerce' );
		return;
	}

	require_once FELAR_CONNECT_DIR . 'includes/class-felar-contrat.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-notes.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-taxes.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-categories.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-convertisseur.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-rapport.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-reponse.php';
	require_once FELAR_CONNECT_DIR . 'includes/interface-felar-transport.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-transport-wp.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-client.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-journal.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-liens.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-reglages.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-lecteur.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-analyse.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-import.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-references.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-stock-regles.php';
	require_once FELAR_CONNECT_DIR . 'includes/class-felar-stock.php';
	require_once FELAR_CONNECT_DIR . 'admin/class-felar-admin.php';

	$reglages   = new Felar_Reglages();
	$lecteur    = new Felar_Lecteur();
	$analyse    = new Felar_Analyse( $reglages, $lecteur );
	$import     = new Felar_Import( $reglages, $lecteur );
	$references = new Felar_References();
	$stock      = new Felar_Stock( $reglages );

	// Les tâches de fond se branchent partout, pas seulement dans
	// l'administration : elles partent d'une requête anonyme.
	$import->brancher();
	$stock->brancher();

	if ( is_admin() ) {
		$admin = new Felar_Admin( $reglages, $analyse, $import, $references, $stock );
		$admin->brancher();
	}
}
add_action( 'plugins_loaded', 'felar_connect_demarrer' );

/** Le message quand WooCommerce manque. */
function felar_connect_sans_woocommerce() {
	echo '<div class="notice notice-error"><p><strong>Felar Connect</strong> a besoin de '
		. 'WooCommerce pour fonctionner : il lit vos produits et vos commandes. '
		. 'Activez WooCommerce, ou désactivez Felar Connect.</p></div>';
}

/**
 * Annonce la compatibilité avec le stockage moderne des commandes (HPOS).
 *
 * WooCommerce range depuis peu les commandes dans ses propres tables. Une
 * extension qui ne le déclare pas est signalée comme incompatible et peut
 * empêcher le marchand d'activer le nouveau stockage. Rien ici ne lit une
 * commande en SQL direct : la déclaration est donc vraie.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				FELAR_CONNECT_FILE,
				true
			);
		}
	}
);

/**
 * À la désactivation : plus aucune tâche ne part.
 *
 * Sans cela, une tranche d'import programmée se réveillerait après la
 * désactivation, appellerait Felar, et écrirait dans le catalogue d'un marchand
 * qui vient justement de dire « arrête ».
 */
register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'felar_connect_import_lot', null, 'felar-connect' );
			as_unschedule_all_actions( 'felar_connect_stock_passage', null, 'felar-connect' );
		}
	}
);
