<?php
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { http_response_code(403); exit; }

$user_id = (int)$_SESSION['user_id'];
$task_id = (int)($_GET['task_id'] ?? 0);
$group_id = (int)($_POST['group_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT f.id, f.file_name, f.file_path, f.user_id, u.name AS uploader,
           gm.role, g.owner_user_id
    FROM task_files f
    JOIN users u ON f.user_id = u.id
    JOIN tasks t ON f.task_id = t.id
    JOIN task_groups g ON t.group_id = g.id
    JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
    WHERE f.task_id = ?
");
$stmt->execute([$user_id, $task_id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($files as &$f) {
    $canDelete = ($f['user_id'] == $user_id || $f['owner_user_id'] == $user_id || in_array($f['role'], ['teacher','admin']));
    $f['can_delete'] = $canDelete;
}

echo json_encode(["files" => $files]);

?>

<script>
document.getElementById("toggleFiles").addEventListener("click", async () => {
  const box = document.getElementById("filesBox");
  box.classList.toggle("hidden");

  if (!box.classList.contains("hidden")) {
    const taskId = document.getElementById("uploadTaskId").value; // current task ID
    try {
      const res = await fetch("list_files.php?task_id=" + encodeURIComponent(taskId));
      const data = await res.json();

      const list = document.getElementById("filesList");
      list.innerHTML = "";

      if (!data.files || data.files.length === 0) {
        list.textContent = "No files uploaded.";
      } else {
        data.files.forEach(f => {
          const item = document.createElement("div");
          item.style.marginBottom = "5px";

          item.innerHTML = `
            <a href="${f.file_path}" download>${f.file_name}</a>
            <small>(by ${f.uploader})</small>
            ${f.can_delete ? `<button data-id="${f.id}" class="deleteFileBtn">Delete</button>` : ""}
          `;
          list.appendChild(item);
        });

        // hook delete buttons
        document.querySelectorAll(".deleteFileBtn").forEach(btn => {
          btn.addEventListener("click", async e => {
            const id = e.target.dataset.id;
            if (confirm("Delete this file?")) {
              const delRes = await fetch("delete_file.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id })
              });
              const delData = await delRes.json();
              if (delData.success) {
                e.target.parentElement.remove();
              } else {
                alert(delData.error || "Failed to delete");
              }
            }
          });
        });
      }
    } catch (err) {
      console.error(err);
      document.getElementById("filesList").textContent = "Error loading files.";
    }
  }
});
</script>