<?php
/**
 * La désinstallation.
 *
 * Elle efface ce que l'extension a écrit dans WordPress : ses réglages, son
 * journal, son état d'avancement et les liens posés sur les produits. Elle ne
 * touche <b>rien dans Felar</b> — ni les fiches montées, ni la clé, qui se révoque
 * là-bas et seulement là-bas.
 *
 * Le lien lui-même ne se perd pas : Felar retient l'identifiant WooCommerce de
 * chaque produit. Une réinstallation suivie d'un nouvel import met donc à jour les
 * fiches existantes, sans en créer de secondes.
 *
 * @package Felar_Connect
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'felar_connect_reglages' );
delete_option( 'felar_connect_journal' );
delete_option( 'felar_connect_analyse' );
delete_option( 'felar_connect_import' );

global $wpdb;

// En SQL, volontairement : parcourir quatre mille produits par l'API objet pour
// retirer trois métadonnées ferait expirer la désinstallation. Ce sont des
// métadonnées de produit — HPOS ne concerne que les commandes.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
		'_felar_id',
		'_felar_reference',
		'_felar_envoi'
	)
);
