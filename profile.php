<?php
$rootDir = __DIR__;
require_once $rootDir . '/includes/auth.php';
require_login('profile.php');
require_once $rootDir . '/includes/db.php';
require_once $rootDir . '/includes/media_storage.php';

$sessionUser = get_logged_in_user() ?? [];
$userId = (int)($sessionUser['id'] ?? 0);
$feedbackMessage = '';
$feedbackType = '';

$profile = [
    'id' => $userId,
    'name' => (string)($sessionUser['name'] ?? 'User'),
    'email' => (string)($sessionUser['email'] ?? ''),
    'department' => 'Emergency Response System',
    'role' => (string)($sessionUser['role'] ?? 'user'),
    'status' => 'active',
    'created_at' => null,
    'updated_at' => null,
    'last_login' => null,
];
$profileNotice = '';
$profileImage = null;
$pdo = null;

try {
    $pdo = get_db_connection();
} catch (Throwable $e) {
    $pdo = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_photo'])) {
    if (!$pdo || $userId <= 0) {
        $feedbackType = 'error';
        $feedbackMessage = 'The profile picture could not be saved because there is no active database connection.';
    } elseif (!isset($_FILES['profile_photo']) || !is_array($_FILES['profile_photo'])) {
        $feedbackType = 'error';
        $feedbackMessage = 'No image file was selected.';
    } else {
        $upload = $_FILES['profile_photo'];
        $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmpPath = (string)($upload['tmp_name'] ?? '');
        $fileSize = (int)($upload['size'] ?? 0);
        $maxFileSize = 5 * 1024 * 1024;
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if ($uploadError !== UPLOAD_ERR_OK) {
            $feedbackType = 'error';
            $feedbackMessage = 'There was a problem while uploading the image.';
        } elseif ($fileSize <= 0 || $fileSize > $maxFileSize) {
            $feedbackType = 'error';
            $feedbackMessage = 'The profile picture must be an image file up to 5MB only.';
        } else {
            $detectedMime = null;
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = finfo_file($finfo, $tmpPath) ?: null;
                    finfo_close($finfo);
                }
            }

            if (!$detectedMime || !isset($allowedMimeTypes[$detectedMime])) {
                $imageInfo = @getimagesize($tmpPath);
                $fallbackMime = isset($imageInfo['mime']) ? (string)$imageInfo['mime'] : '';
                if (isset($allowedMimeTypes[$fallbackMime])) {
                    $detectedMime = $fallbackMime;
                }
            }

            if (!$detectedMime || !isset($allowedMimeTypes[$detectedMime])) {
                $feedbackType = 'error';
                $feedbackMessage = 'Only PNG, JPG, and WEBP images can be uploaded.';
            } else {
                $blob = @file_get_contents($tmpPath);
                if ($blob === false || $blob === '') {
                    $feedbackType = 'error';
                    $feedbackMessage = 'The image file could not be read. Please try again.';
                } else {
                    try {
                        $storedImage = store_profile_image(
                            $pdo,
                            $userId,
                            basename((string)($upload['name'] ?? ('profile.' . $allowedMimeTypes[$detectedMime]))),
                            $detectedMime,
                            $fileSize,
                            $blob
                        );
                        if ($storedImage) {
                            $profileImage = $storedImage;
                            $_SESSION['user_avatar'] = (string)$storedImage['url'];
                            $feedbackType = 'success';
                            $feedbackMessage = 'Your profile picture has been updated.';
                        } else {
                            $feedbackType = 'error';
                            $feedbackMessage = 'The image was uploaded but could not be saved to profile image storage.';
                        }
                    } catch (Throwable $e) {
                        $feedbackType = 'error';
                        $feedbackMessage = 'The profile picture could not be saved to the database.';
                    }
                }
            }
        }
}
}

try {
    if ($pdo && $userId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, name, email, department, role, status, created_at, updated_at, last_login
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $dbProfile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($dbProfile) && !empty($dbProfile)) {
            $profile = array_merge($profile, $dbProfile);
        } else {
            $profileNotice = 'Showing session data because the full account record was not found.';
        }

        $profileImage = get_active_profile_image($pdo, $userId);
    } else {
        $profileNotice = 'Showing session data because the database connection is unavailable.';
    }
} catch (Throwable $e) {
    $profileNotice = 'Showing session data because the profile record could not be loaded right now.';
}

function profile_format_datetime($value): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return 'Not available';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return 'Not available';
    }

    return date('M d, Y h:i A', $timestamp);
}

function profile_format_role($value): string {
    $label = trim((string)$value);
    if ($label === '') {
        return 'User';
    }

    $label = str_replace(['_', '-'], ' ', strtolower($label));
    return ucwords($label);
}

$canonicalRole = canonical_role((string)($profile['role'] ?? ''));
$effectiveRole = $canonicalRole === 'unknown' ? current_session_role() : $canonicalRole;
$roleLabel = profile_format_role((string)($profile['role'] ?? $effectiveRole));
$profileHeading = $effectiveRole === 'admin' ? 'Admin Profile' : 'User Profile';
$profileIntro = $effectiveRole === 'admin'
    ? 'Here is the personal information of the admin account currently logged in to the system.'
    : 'Here is the personal information of the account currently logged in.';
$dashboardPath = role_home_path($effectiveRole !== 'unknown' ? $effectiveRole : 'admin');
$avatarUrl = is_array($profileImage) && !empty($profileImage['url'])
    ? (string)$profileImage['url']
    : 'https://ui-avatars.com/api/?name=' . urlencode((string)$profile['name']) . '&background=1f6f78&color=fff&size=256';
$statusValue = strtolower(trim((string)($profile['status'] ?? 'active')));
$statusClass = $statusValue === 'active' ? 'is-active' : ($statusValue === 'inactive' ? 'is-inactive' : 'is-neutral');
$statusLabel = $statusValue !== '' ? ucfirst($statusValue) : 'Unknown';
$pageTitle = $profileHeading;
$departmentLabel = trim((string)($profile['department'] ?? '')) !== ''
    ? (string)$profile['department']
    : 'Emergency Response System';
$lastLogin = profile_format_datetime($profile['last_login'] ?? null);
$createdAt = profile_format_datetime($profile['created_at'] ?? null);
$updatedAt = profile_format_datetime($profile['updated_at'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/profile.css">
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content">
        <div class="main-container profile-shell">
            <section class="profile-hero">
                <div class="profile-hero-copy">
                    <span class="profile-kicker">Account Overview</span>
                    <h1><?php echo htmlspecialchars($profileHeading); ?></h1>
                    <p class="profile-lead"><?php echo htmlspecialchars($profileIntro); ?></p>

                    <div class="profile-chip-row">
                        <span class="profile-chip"><i class="fas fa-id-badge"></i><?php echo htmlspecialchars($roleLabel); ?></span>
                        <span class="profile-chip"><i class="fas fa-building"></i><?php echo htmlspecialchars($departmentLabel); ?></span>
                        <span class="profile-chip status-chip <?php echo htmlspecialchars($statusClass); ?>"><i class="fas fa-circle"></i><?php echo htmlspecialchars($statusLabel); ?></span>
                    </div>

                    <div class="profile-action-row">
                        <a href="<?php echo htmlspecialchars($dashboardPath); ?>" class="profile-btn profile-btn-primary">
                            <i class="fas fa-house"></i>
                            <span>Back to Dashboard</span>
                        </a>
                        <a href="account_settings.php" class="profile-btn profile-btn-secondary">
                            <i class="fas fa-gear"></i>
                            <span>Open Settings</span>
                        </a>
                    </div>
                </div>

                <aside class="profile-summary-card">
                    <div class="profile-summary-toolbar">
                        <div class="profile-summary-top">
                            <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="<?php echo htmlspecialchars((string)$profile['name']); ?>" class="profile-avatar">
                            <div>
                                <h2><?php echo htmlspecialchars((string)$profile['name']); ?></h2>
                                <p><?php echo htmlspecialchars((string)$profile['email']); ?></p>
                            </div>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="profile-photo-form">
                            <input type="hidden" name="upload_profile_photo" value="1">
                            <input
                                type="file"
                                name="profile_photo"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                class="profile-photo-input"
                                data-profile-photo-input
                            >
                            <button type="button" class="profile-photo-edit-btn" data-profile-photo-trigger>
                                <i class="fas fa-pen"></i>
                                <span>Edit Photo</span>
                            </button>
                            <small class="profile-photo-help">PNG, JPG, or WEBP up to 5MB</small>
                        </form>
                    </div>

                    <div class="profile-summary-grid">
                        <div class="profile-summary-item">
                            <span>Account ID</span>
                            <strong>#<?php echo htmlspecialchars((string)$profile['id']); ?></strong>
                        </div>
                        <div class="profile-summary-item">
                            <span>Last Login</span>
                            <strong><?php echo htmlspecialchars($lastLogin); ?></strong>
                        </div>
                        <div class="profile-summary-item">
                            <span>Member Since</span>
                            <strong><?php echo htmlspecialchars($createdAt); ?></strong>
                        </div>
                        <div class="profile-summary-item">
                            <span>Last Updated</span>
                            <strong><?php echo htmlspecialchars($updatedAt); ?></strong>
                        </div>
                    </div>
                </aside>
            </section>

            <?php if ($profileNotice !== ''): ?>
                <div class="profile-notice">
                    <i class="fas fa-circle-info"></i>
                    <span><?php echo htmlspecialchars($profileNotice); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($feedbackMessage !== ''): ?>
                <div class="profile-notice profile-notice-<?php echo htmlspecialchars($feedbackType !== '' ? $feedbackType : 'info'); ?>">
                    <i class="fas <?php echo $feedbackType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo htmlspecialchars($feedbackMessage); ?></span>
                </div>
            <?php endif; ?>

            <section class="profile-grid">
                <article class="profile-panel">
                    <div class="profile-panel-heading">
                        <div>
                            <span class="profile-section-label">Personal Information</span>
                            <h2>Admin Details</h2>
                        </div>
                        <i class="fas fa-address-card"></i>
                    </div>

                    <div class="profile-info-grid">
                        <div class="profile-info-item">
                            <span>Full Name</span>
                            <strong><?php echo htmlspecialchars((string)$profile['name']); ?></strong>
                        </div>
                        <div class="profile-info-item">
                            <span>Email Address</span>
                            <strong><?php echo htmlspecialchars((string)$profile['email']); ?></strong>
                        </div>
                        <div class="profile-info-item">
                            <span>Department</span>
                            <strong><?php echo htmlspecialchars($departmentLabel); ?></strong>
                        </div>
                        <div class="profile-info-item">
                            <span>Role</span>
                            <strong><?php echo htmlspecialchars($roleLabel); ?></strong>
                        </div>
                        <div class="profile-info-item">
                            <span>Account Status</span>
                            <strong><?php echo htmlspecialchars($statusLabel); ?></strong>
                        </div>
                        <div class="profile-info-item">
                            <span>User ID</span>
                            <strong><?php echo htmlspecialchars((string)$profile['id']); ?></strong>
                        </div>
                    </div>
                </article>

                <article class="profile-panel profile-panel-accent">
                    <div class="profile-panel-heading">
                        <div>
                            <span class="profile-section-label">Access Summary</span>
                            <h2>Account Snapshot</h2>
                        </div>
                        <i class="fas fa-shield-halved"></i>
                    </div>

                    <div class="profile-timeline">
                        <div class="profile-timeline-item">
                            <span>Current Session</span>
                            <strong>Authenticated and active</strong>
                            <p>This account is currently signed in and can access the admin workspace.</p>
                        </div>
                        <div class="profile-timeline-item">
                            <span>Recent Login</span>
                            <strong><?php echo htmlspecialchars($lastLogin); ?></strong>
                            <p>Last recorded login time saved by the system.</p>
                        </div>
                        <div class="profile-timeline-item">
                            <span>Profile Updated</span>
                            <strong><?php echo htmlspecialchars($updatedAt); ?></strong>
                            <p>Latest account record update visible from the user table.</p>
                        </div>
                    </div>
                </article>

                <article class="profile-panel profile-panel-wide">
                    <div class="profile-panel-heading">
                        <div>
                            <span class="profile-section-label">Profile Notes</span>
                            <h2>Main Content View</h2>
                        </div>
                        <i class="fas fa-window-maximize"></i>
                    </div>

                    <p class="profile-body-copy">
                        This page appears when <strong>Profile</strong> is clicked in the user dropdown. The current admin's
                        personal information is displayed here in the main content area to keep the dashboard layout consistent.
                    </p>

                    <div class="profile-meta-row">
                        <div class="profile-meta-card">
                            <span>Email Contact</span>
                            <strong><?php echo htmlspecialchars((string)$profile['email']); ?></strong>
                        </div>
                        <div class="profile-meta-card">
                            <span>Department</span>
                            <strong><?php echo htmlspecialchars($departmentLabel); ?></strong>
                        </div>
                        <div class="profile-meta-card">
                            <span>Member Since</span>
                            <strong><?php echo htmlspecialchars($createdAt); ?></strong>
                        </div>
                    </div>
                </article>
            </section>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const photoTrigger = document.querySelector('[data-profile-photo-trigger]');
        const photoInput = document.querySelector('[data-profile-photo-input]');

        if (!photoTrigger || !photoInput) {
            return;
        }

        photoTrigger.addEventListener('click', function() {
            photoInput.click();
        });

        photoInput.addEventListener('change', function() {
            if (photoInput.files && photoInput.files.length > 0) {
                const form = photoInput.closest('form');
                if (form) {
                    form.submit();
                }
            }
        });
    });
    </script>
</body>
</html>
