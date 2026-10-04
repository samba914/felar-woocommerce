<?php
/**
 * Le rapport, puis l'import.
 *
 * L'ordre des deux boutons n'est pas une question de présentation : on ne lance
 * pas un import de catalogue sans avoir lu ce qu'il va faire. Le bouton d'import
 * reste donc éteint jusqu'à ce que l'analyse soit allée au bout — c'est elle qui
 * repère les UGS portées par deux articles, et cela ne se sait qu'à la fin.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

$felar_etat_import  = $import->etat();
$felar_etat_analyse = $analyse->etat();
$felar_cron_coupe   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
?>

<?php if ( ! $reglages->branchee() ) : ?>
	<div class="notice notice-warning">
		<p>Branchez d'abord une clé dans l'onglet <em>Connexion</em>.</p>
	</div>
<?php endif; ?>

<?php if ( ! $import->ordonnanceur_disponible() ) : ?>
	<div class="notice notice-error">
		<p>
			Action Scheduler est introuvable. Il est livré avec WooCommerce : vérifiez
			que WooCommerce est actif et à jour. Sans lui, un import de plusieurs
			centaines d'articles ne peut pas se faire sans expirer.
		</p>
	</div>
<?php endif; ?>

<?php if ( $felar_cron_coupe ) : ?>
	<div class="notice notice-warning">
		<p>
			<code>DISABLE_WP_CRON</code> est actif sur ce site. Les tâches de fond ne
			partent alors que si un <strong>cron système</strong> appelle
			<code>wp-cron.php</code>. Si l'import reste à zéro, c'est presque toujours
			cela — le bouton <em>Faire avancer</em> permet de le pousser à la main en
			attendant.
		</p>
	</div>
<?php endif; ?>

<div class="felar-grille">

	<div class="felar-carte">
		<h2>1. Lire avant d'importer</h2>
		<p class="description">
			L'analyse ne parle pas à Felar : elle lit votre catalogue et vous dit ce
			qui va se passer. Vous pouvez la relancer autant de fois que vous voulez.
		</p>

		<p class="felar-actions">
			<button type="button" class="button button-primary" id="felar-analyser">
				Analyser mon catalogue
			</button>
			<span id="felar-analyse-avancement" class="felar-avancement" aria-live="polite"></span>
		</p>

		<div id="felar-rapport" class="felar-rapport"
		     data-initial="<?php echo esc_attr( wp_json_encode( $felar_etat_analyse ) ); ?>"></div>
	</div>

	<div class="felar-carte">
		<h2>2. Monter le catalogue dans Felar</h2>

		<?php if ( $reglages->en_essai() ) : ?>
			<p class="felar-essai">
				Clé d'essai : Felar validera tout et <strong>n'enregistrera rien</strong>.
				Les identifiants qu'il renvoie ne sont pas conservés — sans quoi le
				premier import réel croirait n'avoir plus rien à faire.
			</p>
		<?php endif; ?>

		<p class="felar-actions">
			<button type="button" class="button button-primary" id="felar-importer" disabled>
				Lancer l'import
			</button>
			<button type="button" class="button" id="felar-pousser">Faire avancer</button>
			<button type="button" class="button button-link-delete" id="felar-arreter">Arrêter</button>
		</p>

		<div id="felar-import" class="felar-import"
		     data-initial="<?php echo esc_attr( wp_json_encode( $felar_etat_import ) ); ?>"></div>
	</div>

	<div class="felar-carte">
		<h2>3. Ramener les références dans WooCommerce</h2>
		<p class="description">
			Pour les articles qui n'avaient pas d'UGS, Felar en a fabriqué une. Une
			référence qui n'existe que dans Felar est introuvable depuis votre site :
			vous auriez deux vocabulaires pour le même article, l'un au comptoir,
			l'autre à l'écran. Rien n'est écrit chez vous sans ce bouton.
		</p>

		<p class="felar-actions">
			<button type="button" class="button" id="felar-references">
				Écrire les références manquantes
			</button>
			<span id="felar-references-etat" class="felar-avancement" aria-live="polite">
				<?php
				$felar_attente = $references->combien_en_attente();
				echo $felar_attente > 0
					? esc_html( $felar_attente . ' article(s) en attente.' )
					: 'Rien en attente.';
				?>
			</span>
		</p>
		<div id="felar-references-refus" class="felar-resultat"></div>
	</div>
</div>
