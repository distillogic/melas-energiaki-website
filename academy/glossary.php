<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';$user=academy_require_login();require_once __DIR__.'/sales-terms.php';
render_header('Λεξικό όρων',$user);$terms=sales_terms();uksort($terms,'strnatcasecmp');
?>
<section class="ac-panel"><h1>Οι συντομογραφίες, με απλά λόγια</h1><p>Στη θεωρία κάθε μαθήματος εξηγούνται στην πρώτη εμφάνισή τους. Εδώ βρίσκεις συγκεντρωμένες τις ονομασίες και τη σημασία τους. Οι μονάδες ισχύος (kW) δεν είναι μονάδες ενέργειας (kWh).</p><ul class="term-list"><?php foreach($terms as $term=>$definition): ?><li><strong><?= e($term) ?></strong><?= e($definition) ?></li><?php endforeach; ?></ul>
<h2>Πηγές ορολογίας</h2><p>Οι ορισμοί αποδίδονται συνοπτικά για εκπαίδευση πωλητών, όχι για τεχνική μελέτη ή σύμβαση.</p><ul><li><a href="https://help.salesforce.com/s/articleView?id=xcloud.essentials_glossary.htm&amp;language=en_US&amp;type=5">Salesforce · Ορολογία διαχείρισης πελατών</a></li><li><a href="https://www.energy.gov/cmei/systems/solar-photovoltaic-cell-basics">U.S. Department of Energy · Φωτοβολταϊκές κυψέλες</a></li><li><a href="https://www.ise.fraunhofer.de/en/research-projects/topcon.html">Fraunhofer ISE · TOPCon</a></li><li><a href="https://iea-pvps.org/">IEA PVPS · Φωτοβολταϊκά συστήματα</a></li><li><a href="https://2go.iccwbo.org/incoterms-2020-app">ICC · Όροι παράδοσης</a></li></ul></section>
<?php render_footer(); ?>
