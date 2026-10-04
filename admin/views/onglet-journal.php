<?php
/**
 * Les fins de course, pour le marchand.
 *
 * Sans cette liste, une panne silencieuse laisse le stock dériver des semaines et
 * l'écart se découvre à l'inventaire, sans explication. C'est le genre de chose
 * qu'on repousse « en version 2 » et qu'on paie en assistance.
 *
 * @package Felar_Connect
 */

defined( 'ABSPATH' ) || exit;

$felar_entrees = Felar_Journal::entrees();
$felar_verdicts = array(
	'termine' => 'Terminé',
	'echec'   => 'Échec',
	'arrete'  => 'Arrêté',
);
?>

<div class="felar-carte">
	<h2>Journal</h2>

	<?php if ( empty( $felar_entrees ) ) : ?>
		<p>Aucun import n'a encore été lancé depuis cette boutique.</p>
	<?php else : ?>
		<table class="widefat striped felar-journal">
			<thead>
				<tr>
					<th scope="col">Quand</th>
					<th scope="col">Verdict</th>
					<th scope="col">Résumé</th>
					<th scope="col">Chiffres</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $felar_entrees as $felar_entree ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'j F Y à H:i', (int) $felar_entree['quand'] ) ); ?></td>
						<td>
							<?php
							$felar_verdict = (string) $felar_entree['verdict'];
							echo esc_html(
								isset( $felar_verdicts[ $felar_verdict ] )
									? $felar_verdicts[ $felar_verdict ]
									: $felar_verdict
							);
							?>
						</td>
						<td><?php echo esc_html( (string) $felar_entree['resume'] ); ?></td>
						<td>
							<?php
							$felar_chiffres = array();
							foreach ( (array) $felar_entree['chiffre'] as $felar_nom => $felar_valeur ) {
								$felar_chiffres[] = $felar_nom . ' : ' . (int) $felar_valeur;
							}
							echo esc_html( implode( ' — ', $felar_chiffres ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3>Le détail technique</h3>
	<p class="description">
		Les appels et les refus sont écrits dans le journal de WooCommerce, source
		<code>felar-connect</code> : <em>WooCommerce → État → Journaux</em>. Votre clé
		n'y figure jamais — elle y est masquée avant écriture, pour que l'envoi d'un
		journal au support ne soit pas l'envoi d'un mot de passe.
	</p>
</div>
