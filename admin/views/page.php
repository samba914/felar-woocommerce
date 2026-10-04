<?php
/**
 * L'écran, et ses trois onglets.
 *
 * Variables fournies par {@see Felar_Admin::afficher()} : `$reglages`, `$boutique`,
 * `$desaccord`, `$import`, `$analyse`, `$references`, `$onglet`.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

$felar_onglets = array(
	'connexion' => 'Connexion',
	'import'    => 'Import du catalogue',
	'stock'     => 'Stock',
	'journal'   => 'Journal',
);
?>
<div class="wrap felar-connect">

	<h1>Felar Connect</h1>
	<p class="felar-chapeau">
		Votre boutique WooCommerce reste votre vitrine. Felar devient votre
		arrière-boutique : le stock, la caisse, les commandes, les clients et les
		factures.
	</p>

	<?php if ( isset( $_GET['enregistre'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>
	<?php endif; ?>

	<?php if ( ! $reglages->branchee() ) : ?>
		<div class="notice notice-warning">
			<p>
				Aucune clé n'est enregistrée. Ouvrez Felar → <strong>Réglages → Mon
				site</strong>, générez une clé, et collez-la ci-dessous. Felar ne
				l'affiche qu'une seule fois.
			</p>
		</div>
	<?php elseif ( $reglages->en_essai() ) : ?>
		<div class="notice notice-info">
			<p>
				<strong>Mode essai.</strong> Votre clé commence par
				<code>ck_test_</code> : Felar vérifie tout ce que vous envoyez et
				<strong>n'enregistre rien</strong>. C'est fait pour éprouver le
				branchement sans salir votre comptabilité. Passer en production
				consiste à coller une clé <code>ck_live_</code>, rien d'autre.
			</p>
		</div>
	<?php endif; ?>

	<?php if ( null !== $desaccord ) : ?>
		<div class="notice notice-error">
			<p>
				Votre boutique est tenue en <strong><?php echo esc_html( $desaccord['woo'] ); ?></strong>
				et votre compte Felar en <strong><?php echo esc_html( $desaccord['felar'] ); ?></strong>.
				Felar ne convertit rien : un taux de change appliqué en silence produirait
				des factures fausses dont personne ne saurait laquelle est en cause.
				Alignez les deux devises avant d'importer.
			</p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper felar-onglets">
		<?php foreach ( $felar_onglets as $felar_cle => $felar_titre ) : ?>
			<a class="nav-tab <?php echo $onglet === $felar_cle ? 'nav-tab-active' : ''; ?>"
			   href="<?php echo esc_url( admin_url( 'admin.php?page=' . Felar_Admin::PAGE . '&onglet=' . $felar_cle ) ); ?>">
				<?php echo esc_html( $felar_titre ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	if ( 'import' === $onglet ) {
		include FELAR_CONNECT_DIR . 'admin/views/onglet-import.php';
	} elseif ( 'stock' === $onglet ) {
		include FELAR_CONNECT_DIR . 'admin/views/onglet-stock.php';
	} elseif ( 'journal' === $onglet ) {
		include FELAR_CONNECT_DIR . 'admin/views/onglet-journal.php';
	} else {
		include FELAR_CONNECT_DIR . 'admin/views/onglet-connexion.php';
	}
	?>
</div>
