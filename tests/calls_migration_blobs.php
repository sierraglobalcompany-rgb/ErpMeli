<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';

// Package authority is immutable Git content, never Windows checkout bytes.
// Keep the historical worktree-byte test separate; this does not bless its result.
$root=dirname(__DIR__);
$git=static function(array $args)use($root):string {
    $p=proc_open(array_merge(['git','-C',$root],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($p)) throw new RuntimeException('git_start_failed');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0) throw new RuntimeException('git_failed:'.trim($err));
    return (string)$out;
};
$base='191c5ee708d154471a442dfb6cca3324f8609b01';
$head=trim($git(['rev-parse','HEAD']));
$blobs=static function(string $revision)use($git):array {
    $map=[];
    foreach(explode("\0",$git(['ls-tree','-rz',$revision,'--','database/migrations'])) as $entry) {
        if($entry==='') continue;
        if(!preg_match('/^100644 blob ([a-f0-9]{40})\t(database\/migrations\/.+\.sql)$/D',$entry,$m)) throw new RuntimeException('unexpected_migration_entry');
        $map[$m[2]]=$m[1];
    }
    ksort($map);return $map;
};
$expected=$blobs($base);$actual=$blobs($head);
k1b_assert(count($expected)===301,'certified_base_migration_count_changed');
k1b_assert($expected===$actual,'raw_migration_added_removed_or_modified');
k1b_assert($git(['diff','--name-only','HEAD','--','database/migrations'])==='','uncommitted_migration_edit');
$version=trim($git(['show',$head.':VERSION']));
k1b_assert($version==='2.40.1','release_version_changed');
$mutated=$actual;$first=array_key_first($mutated);$mutated[$first]=str_repeat('0',40);
k1b_assert($mutated!==$expected,'negative_blob_mutation_not_detected');
echo json_encode(['status'=>'PASS','authority'=>'RAW_GIT_BLOBS_NOT_WORKTREE_BYTES','base'=>$base,'head'=>$head,'unchanged_migrations'=>count($actual),'version'=>$version,'schema'=>301,'production_changed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
