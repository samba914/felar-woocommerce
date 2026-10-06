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
			<strong>Vos remboursements montent aussi</strong>, dès que vous cochez les
			articles rendus dans WooCommerce : la quantité revient en stock dans Felar,
			et un avoir peut être produit sur la facture. Partiel ou total, c'est la
			même chose.
		</p>

		<p class="description">
			Deux réserves, et elles ont la même raison — un remboursement décide du
			sort de la <em>marchandise</em> autant que de l'argent.
			<strong>Un remboursement sans article ne part pas</strong> : un montant seul
			ne dit pas ce qu'il faut remettre en stock, et deviner fausserait votre
			inventaire. Et <strong>un remboursement passe entier ou pas du tout</strong> :
			si Felar refuse une seule ligne, aucune n'est enregistrée, et le motif
			s'affiche ci-dessous. Un remboursement à moitié enregistré ferait diverger
			votre caisse et votre inventaire sans que rien ne vous le dise.
		</p>

		<p class="description">
			<strong>Passez vos commandes à « Terminée »</strong> quand la marchandise
			part : c'est ce qui dénoue la réservation dans Felar, et c'est aussi ce qui
			autorise un remboursement — on ne rend pas ce qui n'est jamais parti.
		</p>
	</div>

	<div class="felar-carte">
		<h2>Ce qui est monté</h2>
		<div id="felar-cmd-etat"
		     data-initial="<?php echo esc_attr( wp_json_encode( $felar_cmd ) ); ?>"></div>
	</div>
</div>
