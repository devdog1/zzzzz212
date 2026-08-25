<?php

class OTRSDB
{
    private ?PDO $pdo = null;

    public function __construct(array $config)
    {
        $host   = $config['dbhost'] ?? ($config['db']['otrs']['dbhost'] ?? '');
        $dbname = $config['dbname'] ?? ($config['db']['otrs']['dbname'] ?? '');
        $user   = $config['dbuser'] ?? ($config['db']['otrs']['dbuser'] ?? '');
        $pass   = $config['dbpass'] ?? ($config['db']['otrs']['dbpass'] ?? '');

        if (empty($host) || empty($dbname)) {
            return;
        }

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $this->pdo = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function query(string $sql, array $params = []): array
    {
        if (!$this->pdo) return [];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (!$this->pdo) return null;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function getMaintTickets(): array
    {
        $sql = "
        SELECT t.title AS tickettitle, t.tn AS ticketnumber, q.name AS queuename,
               tt.name AS tickettype, ts.name AS statetype, t.id AS ticketid
        FROM ticket t
        INNER JOIN queue q ON t.queue_id = q.id
        INNER JOIN ticket_type tt ON t.type_id = tt.id
        INNER JOIN ticket_state ts ON t.ticket_state_id = ts.id
        WHERE t.queue_id IN ('13','63','15','12','60','47')
          AND t.ticket_state_id IN ('1','4','6')
        ORDER BY t.change_time DESC";

        return $this->query($sql);
    }

    public function getProblemTickets(): array
    {
        $sql = "
        SELECT t.title AS tickettitle, t.tn AS ticketnumber, q.name AS queuename,
               tt.name AS tickettype, ts.name AS statetype,
               t.create_time AS createtime, t.change_time AS changetime, t.id AS ticketid
        FROM ticket t
        INNER JOIN queue q ON t.queue_id = q.id
        INNER JOIN ticket_type tt ON t.type_id = tt.id
        INNER JOIN ticket_state ts ON t.ticket_state_id = ts.id
        WHERE
            (t.ticket_state_id='1' AND t.type_id IN ('5','6')) OR
            (t.ticket_state_id='4' AND t.type_id IN ('5','6')) OR
            (t.ticket_state_id='6' AND t.type_id IN ('5','6')) OR
            (t.ticket_state_id='7' AND t.type_id IN ('5','6')) OR
            (t.ticket_state_id='8' AND t.type_id IN ('5','6'))
        ORDER BY t.change_time DESC";

        return $this->query($sql);
    }

    public function getAllProblemTickets(): array
    {
        $sql = "
        SELECT t.title AS tickettitle, t.tn AS ticketnumber, q.name AS queuename,
               tt.name AS tickettype, ts.name AS statetype,
               t.create_time AS createtime, t.change_time AS changetime, t.id AS ticketid
        FROM ticket t
        INNER JOIN queue q ON t.queue_id = q.id
        INNER JOIN ticket_type tt ON t.type_id = tt.id
        INNER JOIN ticket_state ts ON t.ticket_state_id = ts.id
        WHERE t.type_id IN ('5','6')
        ORDER BY t.change_time DESC";

        return $this->query($sql);
    }

    public function getLast25ProblemTickets(): array
    {
        $sql = "
        SELECT t.title AS tickettitle, t.tn AS ticketnumber, q.name AS queuename,
               tt.name AS tickettype, ts.name AS statetype,
               t.create_time AS createtime, t.change_time AS changetime,
               t.id, t.change_time AS lastchange
        FROM ticket t
        INNER JOIN queue q ON t.queue_id = q.id
        INNER JOIN ticket_type tt ON t.type_id = tt.id
        INNER JOIN ticket_state ts ON t.ticket_state_id = ts.id
        WHERE t.type_id IN ('5','6')
          AND t.ticket_state_id IN ('2','3','10')
        ORDER BY t.change_time DESC
        LIMIT 25";

        return $this->query($sql);
    }

    public function getChangeOverview(): array
    {
        $sql = "
        SELECT wo.id AS workOrderId, wo.change_id AS changeId, wo.title AS workOrderTitle,
               wo.planned_start_time AS plannedStartTime, wo.planned_end_time AS plannedEndTime,
               ci.change_number AS changeNumber, ci.title AS changeTitle,
               ci.change_state_id AS changeStateId, gc.name AS changeStatus,
               u.first_name AS firstName, u.last_name AS lastName,
               ci.change_manager_id AS changeManagerID
        FROM change_workorder wo
        INNER JOIN change_item ci ON wo.change_id = ci.id
        INNER JOIN general_catalog gc ON ci.change_state_id = gc.id
        INNER JOIN users u ON ci.change_manager_id = u.id
        WHERE wo.title NOT IN ('Create IEP','WMB Customer Quote')
          AND wo.workorder_type_id = 210
          AND gc.name IN ('Approved','Requested','In Progress')
          AND UNIX_TIMESTAMP(wo.planned_end_time) >
              UNIX_TIMESTAMP(NOW() - INTERVAL 15 DAY)
        GROUP BY changeId
        ORDER BY wo.planned_start_time ASC";

        return $this->query($sql);
    }

    public function getLastChanges(): array
    {
        $sql = "
        SELECT wo.change_id AS changeId,
               ci.change_number AS changeNumber,
               ci.title AS changeTitle,
               gc.name AS changeStatus,
               wo.planned_start_time AS plannedStartTime,
               wo.planned_end_time AS plannedEndTime
        FROM change_workorder wo
        INNER JOIN change_item ci ON wo.change_id = ci.id
        INNER JOIN general_catalog gc ON ci.change_state_id = gc.id
        WHERE wo.title NOT IN ('Create IEP','WMB Customer Quote')
          AND wo.workorder_type_id = 210
          AND gc.name IN ('Successful','Pending PIR','Failed')
        GROUP BY changeId
        ORDER BY wo.planned_end_time DESC";

        return $this->query($sql);
    }

    public function getChangeQuery(): array
    {
        $sql = "
        SELECT wo.id AS workOrderId,
               wo.change_id AS changeId,
               wo.title AS workOrderTitle,
               wo.planned_start_time AS plannedStartTime,
               wo.planned_end_time AS plannedEndTime,
               ci.change_number AS changeNumber,
               ci.title AS changeTitle,
               gc.name AS changeStatus
        FROM change_workorder wo
        INNER JOIN change_item ci ON wo.change_id = ci.id
        INNER JOIN general_catalog gc ON ci.change_state_id = gc.id
        WHERE wo.title NOT IN ('Create IEP','WMB Customer Quote')
          AND wo.workorder_type_id = 210
          AND UNIX_TIMESTAMP(wo.planned_start_time) >
              UNIX_TIMESTAMP(NOW() - INTERVAL 180 DAY)
        GROUP BY workOrderId
        ORDER BY wo.planned_start_time ASC";

        return $this->query($sql);
    }

    public function getUserAssignedTickets(string $userEmail): array
    {
        $login = explode('@', $userEmail)[0] ?? $userEmail;
        if (empty($login)) {
            return [];
        }

        $sql = "
        SELECT t.title AS tickettitle, t.tn AS ticketnumber, q.name AS queuename,
               tt.name AS tickettype, ts.name AS statetype,
               t.create_time AS createtime, t.change_time AS changetime, t.id AS ticketid,
               t.responsible_user_id AS responsible_user_id
        FROM ticket t
        INNER JOIN users u ON t.responsible_user_id = u.id
        INNER JOIN queue q ON t.queue_id = q.id
        LEFT JOIN ticket_type tt ON t.type_id = tt.id
        LEFT JOIN ticket_state ts ON t.ticket_state_id = ts.id
        WHERE u.login = :login
          AND t.ticket_state_id IN (1, 4, 6, 7, 8)
        ORDER BY t.change_time DESC";

        return $this->query($sql, ['login' => $login]);
    }
}
