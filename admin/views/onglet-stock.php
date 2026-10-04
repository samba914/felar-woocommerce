<?php
/**
 * Le stock de Felar vers la boutique.
 *
 * L'écran doit répondre à trois questions, et à aucune autre : est-ce que ça
 * tourne, qu'est-ce que le dernier passage a fait, et qu'est-ce qui n'a pas pu
 * être fait. Sans la troisième, une dérive silencieuse se découvre à l'inventaire.
 *
 * Variables fournies par {@see Felar_Admin::afficher()} : `$reglages`, `$stock`.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

$felar_stock      = $stock->etat();
$felar_cron_coupe = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
?>

<?php if ( ! $reglages->branchee() ) : ?>
	<div class="notice notice-warning">
		<p>Branchez d'abord une clé dans l'onglet <em>Connexion</em>.</p>
	</div>
<?php endif; ?>

<?php if ( ! $stock->ordonnanceur_disponible() ) : ?>
	<div class="notice notice-error">
		<p>
			Action Scheduler est introuvable — il est livré avec WooCommerce. Sans
			lui, la synchronisation ne peut pas se répéter toute seule ; le bouton
			<em>Synchroniser maintenant</em> reste utilisable.
		</p>
	</div>
<?php endif; ?>

<div class="felar-grille">

	<div class="felar-carte">
		<h2>Le stock vient de Felar</h2>
		<p class="description">
			Votre stock bouge à la caisse, à la réception d'une livraison, à
			l'inventaire, dans une commande annulée — des mouvements que votre site ne
			voit pas. Felar les voit tous : c'est lui qui tient la quantité, et cette
			page la recopie sur votre boutique toutes les
			<?php echo esc_html( (int) ( $felar_stock['intervalle'] / 60 ) ); ?> minutes.
		</p>

		<p class="description">
			<strong>Seul le stock circule dans ce sens.</strong> Vos intitulés, vos
			descriptions et vos prix restent les vôtres : ce sont les pages que vos
			clients lisent et que Google indexe. Un prix corrigé dans Felar ne partira
			sur votre site qu'avec votre accord, et cela viendra plus tard.
		</p>

		<p class="felar-actions">
			<button type="button" class="button button-primary" id="felar-stock-bascule"
			        data-actif="<?php echo $felar_stock['actif'] ? '1' : '0'; ?>">
				<?php echo $felar_stock['actif'] ? 'Arrêter la synchronisation' : 'Activer la synchronisation'; ?>
			</button>
			<button type="button" class="button" id="felar-stock-maintenant">Synchroniser maintenant</button>
			<button type="button" class="button" id="felar-stock-tout">Tout relire</button>
		</p>

		<p class="description">
			<em>Tout relire</em> oublie le point de reprise et redemande le catalogue
			entier au passage suivant. C'est sans conséquence : la quantité est
			<strong>écrite</strong>, jamais retranchée, donc la rejouer ne change rien.
		</p>

		<?php if ( $felar_stock['actif'] && 0 === $felar_stock['prochain'] ) : ?>
			<div class="notice notice-warning inline">
				<p>
					La synchronisation est active, mais <strong>aucun passage n'est
					programmé</strong>. C'est presque toujours que les tâches de fond de
					WordPress ne partent pas sur ce site<?php echo $felar_cron_coupe ? ' — <code>DISABLE_WP_CRON</code> y est actif' : ''; ?>.
					Demandez à votre hébergeur un véritable cron système qui appelle
					<code>wp-cron.php</code>, ou poussez à la main avec
					<em>Synchroniser maintenant</em>.
				</p>
			</div>
		<?php endif; ?>
	</div>

	<div class="felar-carte">
		<h2>Le dernier passage</h2>
		<div id="felar-stock-etat"
		     data-initial="<?php echo esc_attr( wp_json_encode( $felar_stock ) ); ?>"></div>
	</div>
</div>
