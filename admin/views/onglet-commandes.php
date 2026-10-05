<?php
/**
 * Les commandes de la boutique vers Felar.
 *
 * Variables fournies par {@see Felar_Admin::afficher()} : `$reglages`, `$commandes`.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

$felar_cmd = $commandes->etat();
?>

<?php if ( ! $reglages->branchee() ) : ?>
	<div class="notice notice-warning">
		<p>Branchez d'abord une clé dans l'onglet <em>Connexion</em>.</p>
	</div>
<?php endif; ?>

<div class="felar-grille">

	<div class="felar-carte">
		<h2>Vos ventes arrivent dans Felar</h2>
		<p class="description">
			Chaque commande monte <strong>dès qu'elle existe</strong>, payée ou non :
			attendre le règlement laisserait passer les commandes à payer à la
			livraison, et Felar réserverait le stock trop tard. Le règlement et
			l'annulation suivent, chacun à son moment.
		</p>
		<p class="description">
			Felar en tire le stock, le chiffre d'affaires, la fiche client et la
			facture. Votre tunnel de commande et vos moyens de paiement ne changent
			pas : Felar ne touche pas à cet argent.
		</p>

		<p class="felar-actions">
			<button type="button" class="button button-primary" id="felar-cmd-bascule"
			        data-actif="<?php echo $felar_cmd['actif'] ? '1' : '0'; ?>">
				<?php echo $felar_cmd['actif'] ? 'Arrêter l\'envoi' : 'Activer l\'envoi'; ?>
			</button>
			<button type="button" class="button" id="felar-cmd-reprise">Reprendre les 25 dernières</button>
		</p>

		<p class="description">
			<em>Reprendre les 25 dernières</em> sert si vous activez l'envoi après
			coup : les commandes passées entre-temps n'ont pas à rester dehors.
			Renvoyer une commande déjà connue de Felar ne crée pas de doublon — il la
			reconnaît et rend celle qu'il a déjà.
		</p>

		<p class="description">
			<strong>Le remboursement ne part pas.</strong> Il décide du sort du stock
			<em>et</em> de la facture ; l'enregistrer à moitié donnerait une
			comptabilité fausse. Traitez-le dans Felar, sur l'écran de la commande.
		</p>
	</div>

	<div class="felar-carte">
		<h2>Ce qui est monté</h2>
		<div id="felar-cmd-etat"
		     data-initial="<?php echo esc_attr( wp_json_encode( $felar_cmd ) ); ?>"></div>
	</div>
</div>
