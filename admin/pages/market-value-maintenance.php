<?php
if (!$admin['r_admin'] && !$admin['r_demo']) { echo '<p>Zugriff verweigert.</p>'; exit; }
function mvmMoney($v){ return number_format((int)$v,0,',',' ') . ' EUR'; }
$scope = isset($_REQUEST['scope']) ? $_REQUEST['scope'] : 'all';
$scopeId = isset($_REQUEST['scope_id']) ? (int)$_REQUEST['scope_id'] : 0;
$limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 500;
$result = null; $error = '';
if (isset($_POST['mvm_action'])) {
    $preview = $_POST['mvm_action'] === 'preview';
    if (!$preview && $admin['r_demo']) $error = 'Im Demo-Modus sind Änderungen gesperrt.';
    elseif (!$preview && (!isset($_POST['confirm_apply']) || $_POST['confirm_apply'] !== '1')) $error = 'Bitte die Sicherheitsbestätigung setzen.';
    elseif (($scope !== 'all') && $scopeId < 1) $error = 'Bitte einen gültigen Bereich auswählen.';
    else {
        try { $result = MarketValueMaintenanceService::run($website,$db,$scope,$scopeId,$preview,$limit,(int)$admin['id']); }
        catch (Exception $e) { $error = $e->getMessage(); }
    }
}
$leagues = MarketValueMaintenanceService::getLeagues($website,$db);
$clubs = MarketValueMaintenanceService::getClubs($website,$db);
$history = array();
try { $history = MarketValueMaintenanceService::getHistory($website,$db,20); } catch (Exception $e) { }
?>
<h1>Marktwerte neu berechnen</h1>
<p>Diese Seite verwendet dieselbe zentrale Berechnung wie der bestehende Job „Spielerwert korrigieren“. Die Vorschau verändert keine Daten.</p>
<?php if($error){ ?><div class="alert alert-error"><?php echo escapeOutput($error); ?></div><?php } ?>
<?php if($result){ ?><div class="alert <?php echo isset($_POST['mvm_action']) && $_POST['mvm_action']==='preview'?'alert-info':'alert-success'; ?>">
<strong><?php echo $_POST['mvm_action']==='preview'?'Vorschau abgeschlossen':'Neuberechnung abgeschlossen'; ?></strong><br>
Verarbeitet: <?php echo $result['processed']; ?> | Geändert: <?php echo $result['changed']; ?> | Erhöht: <?php echo $result['increased']; ?> | Gesenkt: <?php echo $result['decreased']; ?> | Unverändert: <?php echo $result['unchanged']; ?>
</div><?php } ?>
<form method="post" action="index.php" class="form-horizontal">
<input type="hidden" name="site" value="market-value-maintenance">
<div class="control-group"><label class="control-label">Bereich</label><div class="controls"><select name="scope" id="mvm-scope">
<option value="all"<?php if($scope==='all')echo' selected';?>>Alle Spieler</option><option value="league"<?php if($scope==='league')echo' selected';?>>Liga</option><option value="club"<?php if($scope==='club')echo' selected';?>>Verein</option><option value="player"<?php if($scope==='player')echo' selected';?>>Spieler-ID</option></select></div></div>
<div class="control-group"><label class="control-label">Liga / Verein / Spieler</label><div class="controls">
<select id="mvm-league"><option value="">Liga wählen</option><?php foreach($leagues as $x){?><option value="<?php echo (int)$x['id'];?>"><?php echo escapeOutput($x['name']);?></option><?php }?></select>
<select id="mvm-club"><option value="">Verein wählen</option><?php foreach($clubs as $x){?><option value="<?php echo (int)$x['id'];?>"><?php echo escapeOutput($x['name']);?></option><?php }?></select>
<input type="number" id="mvm-player" min="1" placeholder="Spieler-ID"><input type="hidden" name="scope_id" id="mvm-scope-id" value="<?php echo $scopeId;?>"></div></div>
<div class="control-group"><label class="control-label">Max. Spieler je Lauf</label><div class="controls"><input type="number" name="limit" min="1" max="5000" value="<?php echo $limit;?>"><span class="help-inline">Für „Alle Spieler“ auf Shared Hosting empfohlen: 500–1000.</span></div></div>
<div class="control-group"><div class="controls"><label class="checkbox"><input type="checkbox" name="confirm_apply" value="1"> Änderungen wirklich anwenden</label>
<button class="btn btn-info" name="mvm_action" value="preview"><i class="icon-search icon-white"></i> Vorschau</button>
<button class="btn btn-danger" name="mvm_action" value="apply" onclick="return confirm('Marktwerte jetzt wirklich speichern?');"><i class="icon-refresh icon-white"></i> Anwenden</button></div></div></form>
<script>$(function(){function sync(){var s=$('#mvm-scope').val(),v=0;if(s==='league')v=$('#mvm-league').val();if(s==='club')v=$('#mvm-club').val();if(s==='player')v=$('#mvm-player').val();$('#mvm-scope-id').val(v||0);}$('#mvm-scope,#mvm-league,#mvm-club,#mvm-player').on('change keyup',sync);});</script>
<?php if($result && count($result['details'])){?><h3>Größte Änderungen (max. 200)</h3><table class="table table-striped table-bordered table-condensed"><thead><tr><th>ID</th><th>Spieler</th><th>Verein</th><th>Liga</th><th>Alt</th><th>Neu</th><th>Änderung</th></tr></thead><tbody><?php foreach($result['details'] as $x){?><tr><td><?php echo $x['id'];?></td><td><?php echo escapeOutput($x['name']);?></td><td><?php echo escapeOutput($x['club']);?></td><td><?php echo escapeOutput($x['league']);?></td><td><?php echo mvmMoney($x['old']);?></td><td><?php echo mvmMoney($x['new']);?></td><td><?php echo ($x['delta']>0?'+':'').escapeOutput($x['percent']);?> %</td></tr><?php }?></tbody></table><?php } ?>
<h3>Letzte ausgeführte Läufe</h3><?php if(count($history)){?><table class="table table-striped table-bordered table-condensed"><thead><tr><th>Datum</th><th>Bereich</th><th>Verarbeitet</th><th>Geändert</th><th>Erhöht</th><th>Gesenkt</th><th>Admin-ID</th></tr></thead><tbody><?php foreach($history as $x){?><tr><td><?php echo date('d.m.Y H:i',(int)$x['created_at']);?></td><td><?php echo escapeOutput($x['scope_type']).' '.((int)$x['scope_id']?:'');?></td><td><?php echo (int)$x['processed'];?></td><td><?php echo (int)$x['changed_count'];?></td><td><?php echo (int)$x['increased_count'];?></td><td><?php echo (int)$x['decreased_count'];?></td><td><?php echo (int)$x['admin_id'];?></td></tr><?php }?></tbody></table><?php }else{?><p class="muted">Noch keine protokollierten Läufe. Bitte zuerst das Installations-SQL ausführen.</p><?php } ?>
