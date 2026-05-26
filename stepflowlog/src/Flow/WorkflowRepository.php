<?php

declare(strict_types=1);

namespace StepFlow\Flow;

use Nene2\Database\DatabaseQueryExecutorInterface;

class WorkflowRepository
{
    public function __construct(private readonly DatabaseQueryExecutorInterface $db)
    {
    }

    public function createWorkflow(string $name, string $description, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO workflows (name, description, created_at) VALUES (?, ?, ?)',
            [$name, $description, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findWorkflow(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM workflows WHERE id = ?', [$id]);
    }

    public function addStep(int $workflowId, string $name, int $stepOrder): int
    {
        return $this->db->insert(
            'INSERT INTO workflow_steps (workflow_id, name, step_order) VALUES (?, ?, ?)',
            [$workflowId, $name, $stepOrder],
        );
    }

    /** @return list<array<string, mixed>> */
    public function findSteps(int $workflowId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC',
            [$workflowId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findStep(int $stepId): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne('SELECT * FROM workflow_steps WHERE id = ?', [$stepId]);
    }

    public function createRun(int $workflowId, string $title, int $firstStepId, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO workflow_runs (workflow_id, title, status, current_step_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$workflowId, $title, 'in_progress', $firstStepId, $now, $now],
        );
    }

    /** @return array<string, mixed>|null */
    public function findRun(int $id): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT wr.*, ws.name AS current_step_name, ws.step_order AS current_step_order
             FROM workflow_runs wr
             LEFT JOIN workflow_steps ws ON wr.current_step_id = ws.id
             WHERE wr.id = ?',
            [$id],
        );
    }

    public function updateRun(int $id, string $status, ?int $nextStepId, string $now): void
    {
        $this->db->insert(
            'UPDATE workflow_runs SET status = ?, current_step_id = ?, updated_at = ? WHERE id = ?',
            [$status, $nextStepId, $now, $id],
        );
    }

    public function recordAction(int $runId, int $stepId, string $action, string $actor, string $comment, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO workflow_actions (run_id, step_id, action, actor, comment, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$runId, $stepId, $action, $actor, $comment, $now],
        );
    }

    /** @return list<array<string, mixed>> */
    public function findActions(int $runId): array
    {
        /** @var list<array<string, mixed>> */
        return $this->db->fetchAll(
            'SELECT wa.*, ws.name AS step_name FROM workflow_actions wa
             JOIN workflow_steps ws ON wa.step_id = ws.id
             WHERE wa.run_id = ? ORDER BY wa.id ASC',
            [$runId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findNextStep(int $workflowId, int $currentOrder): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->db->fetchOne(
            'SELECT * FROM workflow_steps WHERE workflow_id = ? AND step_order > ? ORDER BY step_order ASC LIMIT 1',
            [$workflowId, $currentOrder],
        );
    }
}
