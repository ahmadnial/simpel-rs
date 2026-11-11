<?php

namespace Tests\Concurrency;

use App\Http\Controllers\DocumentController;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVerification;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\DocumentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use ZipArchive;

require __DIR__.'/bootstrap.php';

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function signalFile(string $path, array $data = []): void
{
    $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
    file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($temporary, 0600);
    rename($temporary, $path);
}

function awaitFile(string $path, int $seconds = 60): array
{
    $deadline = microtime(true) + $seconds;
    do {
        clearstatcache(true, $path);
        if (is_file($path)) {
            $content = file_get_contents($path);
            if ($content !== '') {
                return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Barrier timeout: '.basename($path));
}

function qcRequest(User $actor, string $method = 'GET', array $payload = []): Request
{
    auth()->setUser($actor);
    $request = Request::create('http://workflow-qc.invalid/dokumen', $method, $payload);
    $request->setUserResolver(fn () => $actor);
    $session = new Store('workflow-qc', new ArraySessionHandler(120));
    $session->start();
    $request->setLaravelSession($session);
    app()->instance('request', $request);

    return $request;
}

function wordFixture(string $directory, string $body): UploadedFile
{
    $path = $directory.'/source-'.bin2hex(random_bytes(8)).'.docx';
    $zip = new ZipArchive;
    verify($zip->open($path, ZipArchive::CREATE) === true, 'Cannot create synthetic Word fixture.');
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$body.'</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();

    return new UploadedFile($path, 'synthetic.docx', null, null, true);
}

function receipt(Document $document, User $owner, bool $markers): array
{
    $request = qcRequest($owner, 'GET', ['markers' => $markers ? '1' : '0']);
    $response = app(DocumentController::class)->previewPdf($request, $document);
    $token = $response->headers->get('X-Document-Review');
    verify(is_string($token) && $token !== '', 'Preview did not issue receipt.');
    $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);

    return ['token' => $token, 'pdf_hash' => $data['pdf_hash']];
}

function action(array $task, Document $document, User $actor, ?DocumentVerification $ticket, string $directory): void
{
    $request = qcRequest($actor, 'POST', $task['payload'] ?? []);
    $service = app(DocumentService::class);
    match ($task['action']) {
        'approve' => $service->setujui($ticket, 'Synthetic concurrency approval'),
        'revise' => $service->mintaRevisi($ticket, 'Synthetic concurrency revision'),
        'return' => $service->tolakTandaTangan($document, 'Synthetic concurrency signer return'),
        'submit' => app(DocumentController::class)->ajukan($request, $document),
        'upload' => $service->simpanVersi($document, wordFixture($directory, 'Synthetic replacement bytes')),
        default => throw new RuntimeException('Unknown action'),
    };
}

function worker(string $manifestPath, string $side): void
{
    $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $directory = dirname($manifestPath);
    bootWorkflowQc($manifest['root']);
    $task = $manifest[$side];
    $document = Document::findOrFail($manifest['document_id']);
    verify(str_starts_with($document->judul, 'QC-'.$manifest['tag'].'-'), 'Refusing a non-fixture document.');
    $actor = User::findOrFail($task['actor_id']);
    verify(str_ends_with($actor->email, '-'.$manifest['tag'].'@workflow-qc.invalid'), 'Refusing a non-fixture actor.');
    $ticket = isset($task['ticket_id']) ? DocumentVerification::findOrFail($task['ticket_id']) : null;
    verify($ticket === null || $ticket->document_id === (int) $document->id, 'Ticket does not belong to the fixture document.');
    $session = (int) DB::selectOne('SELECT @@SPID AS id')->id;
    signalFile($directory.'/'.$side.'.ready', ['session_id' => $session]);
    awaitFile($directory.'/start');
    $started = microtime(true);
    $status = 200;
    $error = null;
    try {
        if ($side === 'a') {
            DB::beginTransaction();
            Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            signalFile($directory.'/a.locked');
            awaitFile($directory.'/release');
        } else {
            awaitFile($directory.'/a.locked');
            signalFile($directory.'/b.attempting');
        }
        action($task, $document, $actor, $ticket, $manifest['root']);
        if ($side === 'a') {
            DB::commit();
        }
    } catch (Throwable $e) {
        if ($side === 'a' && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $status = match (true) {
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof AuthorizationException => $e->status() ?? 403,
            $e instanceof ValidationException => 422,
            default => 500,
        };
        $error = ['type' => get_class($e), 'code' => (string) $e->getCode(), 'message' => substr($e->getMessage(), 0, 400)];
    }
    signalFile($directory.'/'.$side.'.result', ['session_id' => $session, 'status' => $status, 'elapsed_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => $error]);
}

function fingerprints(): array
{
    $order = ['documents' => 'id', 'document_versions' => 'id', 'document_verifications' => 'id', 'users' => 'id', 'units' => 'id', 'document_types' => 'id', 'workflow_templates' => 'id', 'workflow_steps' => 'id', 'workflow_step_verifiers' => 'id', 'roles' => 'id', 'model_has_roles' => 'role_id,model_id,model_type', 'audit_logs' => 'id', 'notifications' => 'id', 'signature_otp_challenges' => 'id', 'signing_ceremonies' => 'id', 'audit_chain_events' => 'id'];
    $result = [];
    foreach ($order as $table => $columns) {
        $rows = DB::select("SELECT * FROM [$table] ORDER BY $columns");
        $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE))];
    }

    return $result;
}

function fixture(string $root, string $tag): array
{
    return DB::transaction(function () use ($tag) {
        $unit = Unit::create(['nama' => 'QC-'.$tag, 'kode' => 'QC'.$tag, 'urutan' => 999, 'is_active' => true]);
        $users = [];
        foreach (['owner', 'first', 'second', 'signer'] as $name) {
            $users[$name] = User::create(['name' => 'QC-'.$tag.'-'.$name, 'email' => $name.'-'.$tag.'@workflow-qc.invalid', 'password' => password_hash(bin2hex(random_bytes(20)), PASSWORD_BCRYPT, ['cost' => 4]), 'unit_id' => $unit->id, 'is_active' => true]);
        }
        $role = Role::create(['name' => 'qc_signer_'.$tag, 'guard_name' => 'web']);
        // Fixture setup only: avoid emitting unrelated immutable security events.
        DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_id' => $users['signer']->id, 'model_type' => User::class]);
        $type = DocumentType::create(['nama' => 'QC-'.$tag, 'kode' => 'QC'.$tag, 'singkatan' => 'QC', 'format_nomor' => '{urut}/QC/{tahun}', 'is_active' => true, 'urutan' => 999]);
        $workflows = [];
        WorkflowStep::withoutEvents(function () use (&$workflows, $type, $users, $role, $tag) {
            foreach ([1, 2] as $quorum) {
                $workflow = WorkflowTemplate::create(['nama' => 'QC-'.$tag.'-'.$quorum, 'document_type_id' => $type->id, 'is_active' => true, 'is_default' => false]);
                $step = WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 1, 'nama_tahap' => 'QC review', 'tipe' => 'verifikasi', 'mode_verifikasi' => 'parallel', 'min_approval' => $quorum]);
                foreach (['first', 'second'] as $name) {
                    $step->verifierPool()->create(['tipe_pool' => 'user', 'user_id' => $users[$name]->id]);
                }
                WorkflowStep::create(['workflow_template_id' => $workflow->id, 'urutan' => 2, 'nama_tahap' => 'QC signer', 'tipe' => 'penandatangan', 'role_nama' => $role->name, 'mode_verifikasi' => 'serial']);
                $workflows[$quorum] = $workflow->id;
            }
        });

        return ['unit_id' => $unit->id, 'type_id' => $type->id, 'role_id' => $role->id, 'user_ids' => array_map(fn ($user) => (int) $user->id, $users), 'workflow_ids' => $workflows, 'document_ids' => []];
    });
}

function pair(array $manifest, string $directory): array
{
    mkdir($directory, 0700);
    $path = $directory.'/manifest.json';
    signalFile($path, $manifest);
    $processes = [];
    try {
        foreach (['a', 'b'] as $side) {
            $processes[$side] = proc_open([PHP_BINARY, __FILE__, 'worker', $path, $side], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/'.$side.'.stdout', 'w'], 2 => ['file', $directory.'/'.$side.'.stderr', 'w']], $pipes);
            verify(is_resource($processes[$side]), 'Cannot start worker.');
        }
        $a = awaitFile($directory.'/a.ready');
        $b = awaitFile($directory.'/b.ready');
        verify($a['session_id'] !== $b['session_id'], 'Workers must use independent SQL sessions.');
        signalFile($directory.'/start');
        awaitFile($directory.'/a.locked');
        awaitFile($directory.'/b.attempting');
        $blocked = null;
        $deadline = microtime(true) + 10;
        do {
            $row = DB::selectOne('SELECT session_id,blocking_session_id,wait_type FROM sys.dm_exec_requests WHERE session_id = ?', [$b['session_id']]);
            if ($row && (int) $row->blocking_session_id === $a['session_id']) {
                $blocked = (array) $row;
                break;
            }
            usleep(30000);
        } while (microtime(true) < $deadline);
        signalFile($directory.'/release');
        $result = ['a' => awaitFile($directory.'/a.result', 90), 'b' => awaitFile($directory.'/b.result', 90), 'observed_block' => $blocked];
        verify($blocked !== null, 'SQL Server did not expose the expected A-blocks-B wait.');

        return $result;
    } finally {
        signalFile($directory.'/release');
        foreach ($processes as $process) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }
}

function cleanup(array $fixture): void
{
    DB::transaction(function () use ($fixture) {
        $documents = $fixture['document_ids'];
        $users = array_values($fixture['user_ids']);
        verify(DB::table('signing_ceremonies')->whereIn('document_id', $documents)->count() === 0, 'Unexpected ceremony; refusing automatic deletion of signing evidence.');
        verify(DB::table('signature_otp_challenges')->whereIn('document_id', $documents)->count() === 0, 'Unexpected OTP evidence.');
        DB::table('notifications')->whereIn('notifiable_id', $users)->where('notifiable_type', User::class)->delete();
        DB::table('audit_logs')->whereIn('user_id', $users)->delete();
        DB::table('document_verifications')->whereIn('document_id', $documents)->delete();
        DB::table('document_versions')->whereIn('document_id', $documents)->delete();
        DB::table('documents')->whereIn('id', $documents)->delete();
        $steps = DB::table('workflow_steps')->whereIn('workflow_template_id', $fixture['workflow_ids'])->pluck('id')->all();
        DB::table('workflow_step_verifiers')->whereIn('workflow_step_id', $steps)->delete();
        DB::table('workflow_steps')->whereIn('id', $steps)->delete();
        DB::table('workflow_templates')->whereIn('id', $fixture['workflow_ids'])->delete();
        DB::table('document_types')->where('id', $fixture['type_id'])->delete();
        DB::table('model_has_roles')->where('role_id', $fixture['role_id'])->whereIn('model_id', $users)->delete();
        DB::table('roles')->where('id', $fixture['role_id'])->delete();
        DB::table('users')->whereIn('id', $users)->delete();
        DB::table('units')->where('id', $fixture['unit_id'])->delete();
    });
}

function run(): void
{
    verify(in_array('--database=simpel-dev', $_SERVER['argv'], true) && in_array('--allow-staging-writes', $_SERVER['argv'], true), 'Explicit --database=simpel-dev --allow-staging-writes required.');
    $tag = bin2hex(random_bytes(4));
    $root = sys_get_temp_dir().'/simpel-sqlserver-qc-'.$tag;
    mkdir($root, 0700);
    bootWorkflowQc($root);
    $before = fingerprints();
    $fixture = fixture($root, $tag);
    $results = [];
    $cleanup = false;
    $cases = [
        'approve-approve' => ['approve', 'approve', 1],
        'approve-revise' => ['approve', 'revise', 1],
        'revise-approve' => ['revise', 'approve', 1],
        'quorum-approve-approve' => ['approve', 'approve', 2],
        'quorum-approve-revise' => ['approve', 'revise', 2],
        'submit-submit' => ['submit', 'submit', 1],
        'upload-submit' => ['upload', 'submit', 1],
        'submit-upload' => ['submit', 'upload', 1],
        'return-stale-approve' => ['return', 'approve', 1],
        'return-return' => ['return', 'return', 1],
    ];
    $error = null;
    try {
        foreach ($cases as $name => [$actionA, $actionB, $quorum]) {
            echo json_encode(['phase' => 'start', 'scenario' => $name, 'database' => 'simpel-dev']), "\n";
            flush();
            $owner = User::findOrFail($fixture['user_ids']['owner']);
            qcRequest($owner);
            $document = app(DocumentService::class)->uploadDraft(['judul' => 'QC-'.$tag.'-'.$name, 'document_type_id' => $fixture['type_id'], 'workflow_template_id' => $fixture['workflow_ids'][$quorum]], wordFixture($root, '${tanggal_surat}'));
            $fixture['document_ids'][] = (int) $document->id;
            signalFile($root.'/fixture.json', $fixture);
            $manifest = ['tag' => $tag, 'root' => $root, 'document_id' => (int) $document->id, 'a' => ['action' => $actionA], 'b' => ['action' => $actionB]];
            $originalSourcePath = $document->fresh()->currentVersion->file_path;
            $originalSourceHash = hash_file('sha256', Storage::disk('local')->path($originalSourcePath));
            $initialHistory = null;
            if (in_array($actionA, ['approve', 'revise', 'return'], true)) {
                app(DocumentService::class)->ajukanDokumen($document, []);
                $tickets = $document->verifications()->get()->keyBy('verifikator_id');
                if ($actionA === 'return') {
                    $first = User::findOrFail($fixture['user_ids']['first']);
                    qcRequest($first);
                    app(DocumentService::class)->setujui($tickets[$first->id]);
                    $initialHistory = $tickets[$first->id]->fresh()->getAttributes();
                    $manifest['a']['actor_id'] = $fixture['user_ids']['signer'];
                    $manifest['b']['actor_id'] = $fixture['user_ids'][$actionB === 'return' ? 'signer' : 'first'];
                    if ($actionB === 'approve') {
                        $manifest['b']['ticket_id'] = (int) $tickets[$first->id]->id;
                    }
                } else {
                    foreach (['a' => 'first', 'b' => 'second'] as $side => $userKey) {
                        $manifest[$side] += ['actor_id' => $fixture['user_ids'][$userKey], 'ticket_id' => (int) $tickets[$fixture['user_ids'][$userKey]]->id];
                    }
                }
            } else {
                $reviewA = receipt($document->fresh(), $owner, false);
                $reviewB = receipt($document->fresh(), $owner, true);
                foreach (['a' => $reviewA, 'b' => $reviewB] as $side => $review) {
                    $manifest[$side] += ['actor_id' => $owner->id, 'payload' => ['review_token' => $review['token'], 'ajukan_langsung' => 1]];
                }
            }
            $result = pair($manifest, $root.'/'.$name);
            $document = $document->fresh('currentVersion');
            $tickets = $document->verifications()->get();
            $currentSource = Storage::disk('local')->path($document->currentVersion->file_path);
            verify(is_file($currentSource), 'Current source is missing after concurrent actions.');
            if ($actionA === 'upload') {
                verify($document->currentVersion->file_path !== $originalSourcePath && hash_file('sha256', $currentSource) !== $originalSourceHash, 'Replacement did not persist a distinct source.');
                verify(! Storage::disk('local')->exists($originalSourcePath), 'Unsubmitted replaced source was not cleaned after commit.');
            } else {
                verify($document->currentVersion->file_path === $originalSourcePath && hash_file('sha256', $currentSource) === $originalSourceHash, 'Concurrent action changed a protected source.');
            }
            $statusB = match ($name) {
                'quorum-approve-approve', 'quorum-approve-revise' => 200,
                'submit-submit', 'return-return' => 403,
                'upload-submit' => 422,
                default => 409,
            };
            verify($result['a']['status'] === 200 && $result['b']['status'] === $statusB, 'Unexpected action outcome for '.$name.': '.json_encode($result));
            $expectedStatus = match ($name) {
                'revise-approve', 'quorum-approve-revise' => Document::STATUS_REVISI,
                'submit-submit', 'submit-upload' => Document::STATUS_DIAJUKAN,
                'upload-submit' => Document::STATUS_DRAFT,
                'return-stale-approve', 'return-return' => Document::STATUS_DITOLAK_TTD,
                default => Document::STATUS_MENUNGGU_TTD,
            };
            verify($document->status === $expectedStatus, 'Unexpected final document state for '.$name);
            $duplicates = DB::select('SELECT document_id,document_version_id,workflow_step_id,verifikator_id,level,COUNT(*) AS n FROM document_verifications WHERE document_id=? AND status=? GROUP BY document_id,document_version_id,workflow_step_id,verifikator_id,level HAVING COUNT(*)>1', [$document->id, 'menunggu']);
            verify($duplicates === [], 'Duplicate active ticket.');
            if ($initialHistory !== null) {
                verify(DocumentVerification::findOrFail($initialHistory['id'])->getAttributes() === $initialHistory, 'Historical decision was mutated.');
                verify($tickets->where('status', 'menunggu')->count() === 2 && $tickets->max('verification_round') === 2, 'Signer return must create exactly one new round.');
            } elseif (str_contains($name, 'submit') || str_contains($name, 'upload')) {
                verify($document->versions()->count() === 1, 'Concurrent action created an unexpected version.');
                $files = Storage::disk('local')->files('documents/'.$document->id.'/reviewed');
                if ($actionA === 'submit') {
                    verify(count($files) === 1 && $files[0] === $document->currentVersion->file_pdf_path, 'Unexpected/orphan review snapshot.');
                    verify(hash_file('sha256', Storage::disk('local')->path($files[0])) === $reviewA['pdf_hash'], 'Committed snapshot hash changed.');
                    verify(DB::table('audit_logs')->where('model_id', $document->id)->where('model_type', Document::class)->where('aksi', 'ajukan')->count() === 1, 'Duplicate submission audit.');
                } else {
                    verify($files === [] && $document->currentVersion->file_pdf_path === null && $tickets->isEmpty(), 'Rejected stale review left partial submission.');
                }
            } else {
                verify($tickets->where('status', 'menunggu')->isEmpty(), 'A terminal verification decision left pending peers.');
                if ($name === 'quorum-approve-approve') {
                    verify($tickets->where('status', 'disetujui')->pluck('decided_by_user_id')->unique()->count() === 2, 'Quorum must include two distinct actual actors.');
                }
            }
            $result += ['scenario' => $name, 'final_status' => $document->status, 'tickets' => $tickets->count(), 'pending' => $tickets->where('status', 'menunggu')->count(), 'passed' => true];
            $results[] = $result;
            echo json_encode($result), "\n";
            flush();
        }
    } catch (Throwable $e) {
        $error = ['type' => get_class($e), 'message' => substr($e->getMessage(), 0, 1200)];
    } finally {
        try {
            cleanup($fixture);
            $cleanup = true;
        } catch (Throwable $e) {
            $error ??= ['type' => get_class($e), 'message' => 'Fixture cleanup failed: '.substr($e->getMessage(), 0, 400)];
        }
        $after = fingerprints();
        $summary = ['database' => 'simpel-dev', 'run_id' => $tag, 'scenarios' => $results, 'cleanup_completed' => $cleanup, 'baseline_rows_unchanged' => $before === $after, 'baseline_before' => $before, 'baseline_after' => $after, 'error' => $error, 'production_accessed' => false, 'artifacts' => $root];
        signalFile($root.'/results.json', $summary);
        echo json_encode(['summary' => $summary]), "\n";
    }
    verify($error === null && $cleanup && $before === $after && count($results) === count($cases), 'Concurrency QC did not pass. See '.$root.'/results.json');
}

try {
    if (($argv[1] ?? '') === 'worker') {
        worker($argv[2], $argv[3]);
    } else {
        run();
    }
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['fatal' => get_class($e), 'message' => substr($e->getMessage(), 0, 1500)])."\n");
    exit(1);
}
