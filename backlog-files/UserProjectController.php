<?php



namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\BacklogProject;
use App\Models\Backlogs;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;
use App\Helpers\LogConstants;
use Illuminate\Support\Facades\Log;
use App\Services\GitHubService;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class UserProjectController extends Controller
{

    protected $githubService;

    public function __construct(GitHubService $githubService)
    {
        $this->githubService = $githubService;
    }

    public function index(Request $request)
    {

        $backlogs_id = $request->id;
        $id = Auth()->user()->id;
        $usedBacklogs = BacklogProject::where('uploaded_by', $id)->pluck('backlog_id')->toArray(); // Get backlog IDs that are in backlog_projects
        // dd($usedBacklogs, $backlogs_id);
        // dd(today());
        $backlogs = Backlogs::whereIn('status_id', [2])
            ->where('created_by', '!=', $id)
            ->whereNotIn('id', $usedBacklogs)
            ->whereDate('deadline', '>', Carbon::today()->endOfDay()) // Include all of today
            // ->where(function ($query) {
            //     // Only show backlogs that haven't reached their deadline or have no deadline
            //     $query
            //     //     ->orWhereNull('deadline');
            // }) 
            ->get();
        // dd($backlogs);
        // Add this before the return statement in your controller
        // dd([
        //     'user_id' => $id,
        //     'user_submitted_backlogs' => $usedBacklogs->toArray(),
        //     'total_backlogs_in_db' => Backlogs::count(),
        //     'approved_backlogs' => Backlogs::whereIn('status_id', [2, 4])->count(),
        //     'available_backlogs' => $backlogs->count(),
        //     'backlogs_data' => $backlogs->toArray()
        // ]);
        return view('user.projects', compact('backlogs', 'backlogs_id'));
    }


    // public function saveProject(Request $request)
    // {
    //     $id = Auth()->user()->id;
    //     $validatedData = $request->validate([
    //         'backlog_id' => 'required',
    //         'project_title' => 'required|max:100',
    //         'project_description' => 'required|max:255',
    //         'git_url' => 'required|max:255',
    //         'project_file' => 'required',
    //         'project_file.*' => 'file|max:102400', 
    //     ]);

    //     $backlog_id = $request->backlog_id;

    //     try {
    //         // Create the project record
    //         $record = new BacklogProject();
    //         $record->title = $request->project_title;
    //         $record->description = $request->project_description;
    //         $record->git_url = $request->git_url;
    //         $record->uploaded_by = $id;
    //         $record->backlog_id = $backlog_id;
    //         $record->status = '0'; 

    //         // Handle multiple file uploads (single file, folder, or ZIP)
    //         $uploadResults = ['local_files' => [], 'upload_data' => [], 'upload_type' => 'none'];

    //         if ($request->hasFile('project_file')) {
    //             // Use the same upload handling logic as backlogs
    //             $uploadResults = $this->autoDetectAndHandleUploads($request, 'project_file');

    //             Log::info('Project file upload processing completed', [
    //                 'project_title' => $request->project_title,
    //                 'upload_type' => $uploadResults['upload_type'],
    //                 'total_files' => $uploadResults['total_files'],
    //                 'files_processed' => count($uploadResults['local_files'])
    //             ]);

    //             // Store project folder path instead of single file
    //             if (!empty($uploadResults['local_files'])) {
    //                 // Use project-specific folder for storage
    //                 $projectFolder = '/uploads/projects/' . uniqid('project_' . $id . '_');

    //                 // Make sure folder exists
    //                 $absoluteFolder = public_path($projectFolder);
    //                 if (!File::exists($absoluteFolder)) {
    //                     File::makeDirectory($absoluteFolder, 0755, true);
    //                 }

    //                 // Move all uploaded files into this project folder
    //                 foreach ($uploadResults['local_files'] as $filePath) {
    //                     $source = public_path($filePath);
    //                     $destination = $absoluteFolder . '/' . basename($filePath);
    //                     if (file_exists($source)) {
    //                         File::move($source, $destination);
    //                     }
    //                 }

    //                 // Save only the folder path in DB
    //                 $record->file = $projectFolder;
    //             }
    //         }

    //         // Save the record first to get the ID
    //         $record->save();

    //         // Initialize response data
    //         $responseData = [
    //             'status' => 200,
    //             'message' => 'Project created successfully',
    //             'project_id' => $record->id,
    //             'github_operations' => [],
    //             'file_upload_results' => [
    //                 'upload_type' => $uploadResults['upload_type'],
    //                 'total_files_processed' => count($uploadResults['upload_data']),
    //                 'files_stored_locally' => count($uploadResults['local_files'])
    //             ]
    //         ];

    //         // Add file upload info to success message
    //         if (count($uploadResults['local_files']) > 1) {
    //             $responseData['message'] .= " ({$uploadResults['total_files']} files uploaded locally)";
    //         }

    //         try {
    //             // Load the project with related data for GitHub operations
    //             $project = BacklogProject::with(['backlog', 'user'])->find($record->id);

    //             // Extract repository name from GitHub URL
    //             $repoName = $this->extractRepoNameFromUrl($project->git_url);

    //             if (!$repoName) {
    //                 Log::warning("Could not extract repository name from URL: {$project->git_url}");

    //                 // Update project with warning status
    //                 $record->update([
    //                     'collaboration_status' => 'invalid_github_url'
    //                 ]);

    //                 $responseData['message'] .= ' (Warning: Invalid GitHub URL - branch creation skipped)';
    //                 $responseData['github_operations']['warning'] = 'Invalid GitHub URL format';

    //                 logAction(
    //                     LogConstants::CREATED,
    //                     LogConstants::PROJECT,
    //                     $record->id,
    //                     "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (invalid GitHub URL)."
    //                 );

    //                 return response()->json($responseData);
    //             }

    //             // Generate branch name
    //             $branchName = $this->generateBranchName($project);

    //             // Create branch in GitHub repository
    //             $branchResult = $this->githubService->createBranch($repoName, $branchName);

    //             if ($branchResult['success']) {
    //                 // Branch created successfully
    //                 $responseData['github_operations']['branch_creation'] = 'success';
    //                 $responseData['github_operations']['branch_name'] = $branchName;
    //                 $responseData['message'] .= " Branch '{$branchName}' created for development.";

    //                 // Add user as collaborator to the repository with PULL access only
    //                 $userGithubUsername = $project->user->github_username ?? null;

    //                 if ($userGithubUsername) {
    //                     $collaboratorResult = $this->githubService->addCollaborator($repoName, $userGithubUsername, 'pull');

    //                     if ($collaboratorResult['success']) {
    //                         if (isset($collaboratorResult['invitation_sent']) && $collaboratorResult['invitation_sent']) {
    //                             $responseData['message'] .= " Collaboration invitation sent to {$userGithubUsername}.";
    //                             $responseData['github_operations']['collaboration_status'] = 'invitation_sent';
    //                             $collaborationStatus = 'invitation_sent';
    //                         } elseif (isset($collaboratorResult['already_collaborator']) && $collaboratorResult['already_collaborator']) {
    //                             $responseData['message'] .= " User {$userGithubUsername} already has repository access.";
    //                             $responseData['github_operations']['collaboration_status'] = 'already_collaborator';
    //                             $collaborationStatus = 'already_collaborator';
    //                         } else {
    //                             $responseData['message'] .= " User {$userGithubUsername} added as collaborator with read access.";
    //                             $responseData['github_operations']['collaboration_status'] = 'added';
    //                             $collaborationStatus = 'added';
    //                         }

    //                         Log::info("Successfully managed collaborator access for user {$userGithubUsername} on repository {$repoName}");
    //                     } else {
    //                         $responseData['message'] .= " Warning: Failed to add {$userGithubUsername} as collaborator - {$collaboratorResult['error']}";
    //                         $responseData['github_operations']['collaboration_status'] = 'failed';
    //                         $responseData['github_operations']['collaboration_error'] = $collaboratorResult['error'];
    //                         $collaborationStatus = 'failed';

    //                         Log::warning("Failed to add collaborator {$userGithubUsername} to {$repoName}: " . $collaboratorResult['error']);
    //                     }
    //                 } else {
    //                     $responseData['message'] .= " Warning: No GitHub username found - please add collaborator manually.";
    //                     $responseData['github_operations']['collaboration_status'] = 'no_github_username';
    //                     $collaborationStatus = 'no_github_username';

    //                     Log::warning("No GitHub username found for user {$project->user->id} in project {$record->id}");
    //                 }

    //                 // Update project with GitHub info but keep status as pending
    //                 $record->update([
    //                     'github_branch' => $branchName,
    //                     'github_repo' => $repoName,
    //                     'collaboration_status' => $collaborationStatus ?? 'unknown'
    //                 ]);

    //                 // NOTE: Files are NOT uploaded to GitHub here - they remain local until project is approved
    //                 $responseData['message'] .= " Files will be uploaded to GitHub when project is approved.";

    //                 logAction(
    //                     LogConstants::CREATED,
    //                     LogConstants::PROJECT,
    //                     $record->id,
    //                     "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files stored locally. Branch '{$branchName}' created in repository '{$repoName}'. Collaboration status: " . ($collaborationStatus ?? 'unknown') . ". Files NOT uploaded to GitHub (pending status)."
    //                 );
    //             } else {
    //                 // Branch creation failed
    //                 $responseData['github_operations']['branch_creation'] = 'failed';
    //                 $responseData['github_operations']['branch_error'] = $branchResult['error'];
    //                 $responseData['message'] .= ' (Warning: Branch creation failed - please create manually)';

    //                 $record->update([
    //                     'github_repo' => $repoName,
    //                     'collaboration_status' => 'branch_creation_failed'
    //                 ]);

    //                 Log::error("Branch creation failed for project {$record->id}: " . $branchResult['error']);

    //                 logAction(
    //                     LogConstants::CREATED,
    //                     LogConstants::PROJECT,
    //                     $record->id,
    //                     "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (branch creation failed)."
    //                 );
    //             }
    //         } catch (\Exception $e) {
    //             Log::error("Error creating project with GitHub integration {$record->id}: " . $e->getMessage());

    //             // Update project status to indicate GitHub operations failed
    //             $record->update([
    //                 'collaboration_status' => 'github_operations_failed'
    //             ]);

    //             $responseData['message'] .= ' (Warning: GitHub integration failed)';
    //             $responseData['github_operations']['error'] = $e->getMessage();

    //             logAction(
    //                 LogConstants::CREATED,
    //                 LogConstants::PROJECT,
    //                 $record->id,
    //                 "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (GitHub operations failed)."
    //             );
    //         }

    //         return response()->json($responseData);
    //     } catch (Exception $e) {
    //         Log::error("Error creating project: " . $e->getMessage());

    //         return response()->json([
    //             'status' => 500,
    //             'message' => 'An error occurred while creating the project: ' . $e->getMessage()
    //         ]);
    //     }
    // }


    public function saveProject(Request $request)
    {
        $id = Auth()->user()->id;
        $validatedData = $request->validate([
            'backlog_id' => 'required',
            'project_title' => 'required|max:100',
            'project_description' => 'required|max:255',
            'git_url' => 'required|max:255',
            'project_file' => 'required',
            'project_file.*' => 'file|max:102400',
        ]);

        $backlog_id = $request->backlog_id;

        try {
            // Create the project record
            $record = new BacklogProject();
            $record->title = $request->project_title;
            $record->description = $request->project_description;
            $record->git_url = $request->git_url;
            $record->uploaded_by = $id;
            $record->backlog_id = $backlog_id;
            $record->status = '0';

            // Handle file uploads (store locally for now)
            $uploadResults = ['local_files' => [], 'upload_data' => [], 'upload_type' => 'none'];

            if ($request->hasFile('project_file')) {
                $uploadResults = $this->autoDetectAndHandleUploads($request, 'project_file');

                Log::info('Project file upload processing completed', [
                    'project_title' => $request->project_title,
                    'upload_type' => $uploadResults['upload_type'],
                    'total_files' => $uploadResults['total_files'],
                    'files_processed' => count($uploadResults['local_files'])
                ]);

                if (!empty($uploadResults['local_files'])) {
                    $projectFolder = '/uploads/projects/' . uniqid('project_' . $id . '_');
                    $absoluteFolder = public_path($projectFolder);
                    if (!File::exists($absoluteFolder)) {
                        File::makeDirectory($absoluteFolder, 0755, true);
                    }

                    foreach ($uploadResults['local_files'] as $filePath) {
                        $source = public_path($filePath);
                        $destination = $absoluteFolder . '/' . basename($filePath);
                        if (file_exists($source)) {
                            File::move($source, $destination);
                        }
                    }

                    $record->file = $projectFolder;
                }
            }

            $record->save();

            // Initialize response data
            $responseData = [
                'status' => 200,
                'message' => 'Project created successfully',
                'project_id' => $record->id,
                'github_operations' => [],
                'file_upload_results' => [
                    'upload_type' => $uploadResults['upload_type'],
                    'total_files_processed' => count($uploadResults['upload_data']),
                    'files_stored_locally' => count($uploadResults['local_files'])
                ]
            ];

            if (count($uploadResults['local_files']) > 1) {
                $responseData['message'] .= " ({$uploadResults['total_files']} files uploaded locally)";
            }

            try {
                $project = BacklogProject::with(['backlog', 'user'])->find($record->id);
                $repoName = $this->extractRepoNameFromUrl($project->git_url);

                if (!$repoName) {
                    Log::warning("Could not extract repository name from URL: {$project->git_url}");
                    $record->update(['collaboration_status' => 'invalid_github_url']);
                    $responseData['message'] .= ' (Warning: Invalid GitHub URL - branch creation skipped)';
                    $responseData['github_operations']['warning'] = 'Invalid GitHub URL format';

                    logAction(
                        LogConstants::CREATED,
                        LogConstants::PROJECT,
                        $record->id,
                        "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (invalid GitHub URL)."
                    );

                    return response()->json($responseData);
                }

                // Step 1: Create user branch
                $branchName = $this->generateBranchName($project);
                $branchResult = $this->githubService->createBranch($repoName, $branchName);

                if ($branchResult['success']) {
                    $responseData['github_operations']['branch_creation'] = 'success';
                    $responseData['github_operations']['branch_name'] = $branchName;
                    $responseData['message'] .= " Branch '{$branchName}' created for development.";

                    // Step 2: Add user as collaborator with pull permissions only
                    $userGithubUsername = $project->user->github_username ?? null;

                    if ($userGithubUsername) {
                        $collaboratorResult = $this->githubService->addCollaborator($repoName, $userGithubUsername, 'pull');

                        if ($collaboratorResult['success']) {
                            if (isset($collaboratorResult['invitation_sent']) && $collaboratorResult['invitation_sent']) {
                                $responseData['message'] .= " Collaboration invitation sent to {$userGithubUsername}.";
                                $responseData['github_operations']['collaboration_status'] = 'invitation_sent';
                                $collaborationStatus = 'invitation_sent';
                            } elseif (isset($collaboratorResult['already_collaborator']) && $collaboratorResult['already_collaborator']) {
                                $responseData['message'] .= " User {$userGithubUsername} already has repository access.";
                                $responseData['github_operations']['collaboration_status'] = 'already_collaborator';
                                $collaborationStatus = 'already_collaborator';
                            } else {
                                $responseData['message'] .= " User {$userGithubUsername} added as collaborator with read access.";
                                $responseData['github_operations']['collaboration_status'] = 'added';
                                $collaborationStatus = 'added';
                            }

                            Log::info("Successfully managed collaborator access for user {$userGithubUsername} on repository {$repoName}");
                        } else {
                            $responseData['message'] .= " Warning: Failed to add {$userGithubUsername} as collaborator - {$collaboratorResult['error']}";
                            $responseData['github_operations']['collaboration_status'] = 'failed';
                            $responseData['github_operations']['collaboration_error'] = $collaboratorResult['error'];
                            $collaborationStatus = 'failed';
                            Log::warning("Failed to add collaborator {$userGithubUsername} to {$repoName}: " . $collaboratorResult['error']);
                        }
                    } else {
                        $responseData['message'] .= " Warning: No GitHub username found - please add collaborator manually.";
                        $responseData['github_operations']['collaboration_status'] = 'no_github_username';
                        $collaborationStatus = 'no_github_username';
                        Log::warning("No GitHub username found for user {$project->user->id} in project {$record->id}");
                    }

                    // Step 3: Set up branch protection rules for main branch (if not already done)
                    $this->setupMainBranchProtection($repoName);

                    // Update project with GitHub info but keep status as pending
                    $record->update([
                        'github_branch' => $branchName,
                        'github_repo' => $repoName,
                        'collaboration_status' => $collaborationStatus ?? 'unknown'
                    ]);

                    $responseData['message'] .= " Files will be uploaded to GitHub when project is approved.";

                    logAction(
                        LogConstants::CREATED,
                        LogConstants::PROJECT,
                        $record->id,
                        "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files stored locally. Branch '{$branchName}' created in repository '{$repoName}'. Collaboration status: " . ($collaborationStatus ?? 'unknown') . ". Files NOT uploaded to GitHub (pending status)."
                    );
                } else {
                    $responseData['github_operations']['branch_creation'] = 'failed';
                    $responseData['github_operations']['branch_error'] = $branchResult['error'];
                    $responseData['message'] .= ' (Warning: Branch creation failed - please create manually)';

                    $record->update([
                        'github_repo' => $repoName,
                        'collaboration_status' => 'branch_creation_failed'
                    ]);

                    Log::error("Branch creation failed for project {$record->id}: " . $branchResult['error']);
                    logAction(
                        LogConstants::CREATED,
                        LogConstants::PROJECT,
                        $record->id,
                        "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (branch creation failed)."
                    );
                }
            } catch (\Exception $e) {
                Log::error("Error creating project with GitHub integration {$record->id}: " . $e->getMessage());

                $record->update(['collaboration_status' => 'github_operations_failed']);
                $responseData['message'] .= ' (Warning: GitHub integration failed)';
                $responseData['github_operations']['error'] = $e->getMessage();

                logAction(
                    LogConstants::CREATED,
                    LogConstants::PROJECT,
                    $record->id,
                    "Project id '{$record->id}' was created with " . count($uploadResults['local_files']) . " files (GitHub operations failed)."
                );
            }

            return response()->json($responseData);
        } catch (Exception $e) {
            Log::error("Error creating project: " . $e->getMessage());
            return response()->json([
                'status' => 500,
                'message' => 'An error occurred while creating the project: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Set up main branch protection to prevent direct pushes from collaborators
     */
    private function setupMainBranchProtection($repoName)
    {
        try {
            // Check if protection already exists
            $protectionCheck = $this->githubService->getBranchProtection($repoName, 'main');

            if ($protectionCheck['success'] && $protectionCheck['protection_rules']) {
                Log::info("Main branch protection already exists for {$repoName}");
                return;
            }

            // Set up branch protection rules
            $protectionRules = [
                'required_status_checks' => null,
                'enforce_admins' => false, // Allow repo owner to bypass
                'required_pull_request_reviews' => [
                    'required_approving_review_count' => 1,
                    'dismiss_stale_reviews' => false,
                    'require_code_owner_reviews' => false,
                    'require_last_push_approval' => false
                ],
                'restrictions' => null, // No specific user/team restrictions (use branch protection instead)
                'allow_force_pushes' => false,
                'allow_deletions' => false,
                'block_creations' => false,
                'required_conversation_resolution' => false
            ];

            $result = $this->githubService->enableBranchProtection($repoName, 'main', $protectionRules);

            if ($result['success']) {
                Log::info("Main branch protection enabled for {$repoName}");
            } else {
                Log::warning("Failed to enable main branch protection for {$repoName}: " . $result['error']);
            }
        } catch (\Exception $e) {
            Log::error("Error setting up main branch protection for {$repoName}: " . $e->getMessage());
        }
    }

    private function autoDetectAndHandleUploads(Request $request, $fileFieldName = 'project_file')
    {
        $uploadData = [];
        $localFiles = [];
        $uploadType = 'unknown';

        if ($request->hasFile($fileFieldName)) {
            $files = $request->file($fileFieldName);

            // Handle both single file and multiple files
            if (!is_array($files)) {
                $files = [$files];
            }

            Log::info("Processing project file uploads", ['file_count' => count($files)]);

            // Detect upload type
            $uploadType = $this->detectUploadType($files, $request);

            Log::info("Project upload type detected: {$uploadType}");

            foreach ($files as $index => $uploadedFile) {
                $originalName = $uploadedFile->getClientOriginalName();
                $extension = strtolower($uploadedFile->getClientOriginalExtension());

                // Handle ZIP files
                if ($extension === 'zip') {
                    $zipResults = $this->handleZipFile($uploadedFile, $index, 'project');
                    $uploadData = array_merge($uploadData, $zipResults['upload_data']);
                    $localFiles = array_merge($localFiles, $zipResults['local_files']);
                } else {
                    // Handle regular files (including files from folders)
                    $fileResults = $this->handleRegularFile($uploadedFile, $index, $request, 'project');
                    $uploadData[] = $fileResults['upload_data'];
                    $localFiles[] = $fileResults['local_file'];
                }
            }
        }

        return [
            'upload_data' => $uploadData,
            'local_files' => $localFiles,
            'upload_type' => $uploadType,
            'total_files' => count($uploadData)
        ];
    }

    private function detectUploadType($files, $request)
    {
        // Check if it's a folder upload (webkitdirectory sends relative paths)
        $hasRelativePaths = false;
        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            if (strpos($originalName, '/') !== false || strpos($originalName, '\\') !== false) {
                $hasRelativePaths = true;
                break;
            }
        }

        if ($hasRelativePaths) {
            return 'folder';
        }

        // Check if any file is a ZIP
        foreach ($files as $file) {
            if (strtolower($file->getClientOriginalExtension()) === 'zip') {
                return 'zip';
            }
        }

        // Check if multiple files
        if (count($files) > 1) {
            return 'multiple_files';
        }

        return 'single_file';
    }

    private function handleRegularFile($uploadedFile, $index, $request, $prefix = 'project')
    {
        $originalName = $uploadedFile->getClientOriginalName();
        $fileName = $prefix . '_files_' . time() . '_' . $index . '_' . $originalName;
        $imagePath = '/uploads/images';

        // Ensure upload directory exists
        $uploadDir = public_path($imagePath);
        if (!File::exists($uploadDir)) {
            File::makeDirectory($uploadDir, 0755, true);
        }

        $uploadedFile->move($uploadDir, $fileName);
        $localFilePath = $imagePath . '/' . $fileName;

        // Determine GitHub path (if needed for future GitHub integration)
        $githubPath = 'project-files/';

        // If file has path separators, it might be from a folder upload
        if (strpos($originalName, '/') !== false) {
            $githubPath .= $originalName;
        } else {
            $githubPath .= $originalName;
        }

        return [
            'upload_data' => [
                'file_path' => public_path($localFilePath),
                'github_path' => $githubPath,
                'original_name' => $originalName,
                'local_path' => $localFilePath
            ],
            'local_file' => $localFilePath
        ];
    }

    private function handleZipFile($zipFile, $index, $prefix = 'project')
    {
        $uploadData = [];
        $localFiles = [];

        try {
            // Create temp directory for extraction
            $tempDir = storage_path('app/temp/' . $prefix . '_zip_' . time() . '_' . $index . '_' . uniqid());
            if (!File::exists($tempDir)) {
                File::makeDirectory($tempDir, 0755, true);
            }

            // Save uploaded zip temporarily
            $tempZipPath = $tempDir . '/uploaded.zip';
            $zipFile->move($tempDir, 'uploaded.zip');

            // Extract ZIP file
            $zip = new ZipArchive;
            if ($zip->open($tempZipPath) === TRUE) {
                $zip->extractTo($tempDir . '/extracted');
                $zip->close();

                // Process extracted files
                $extractedDir = $tempDir . '/extracted';
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($extractedDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && !$this->isSystemFile($file->getFilename())) {
                        // Get relative path from extracted directory
                        $relativePath = str_replace($extractedDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                        $relativePath = str_replace('\\', '/', $relativePath); // Convert Windows paths

                        // Generate unique filename for local storage
                        $uniqueName = $prefix . '_extracted_' . time() . '_' . $index . '_' . uniqid() . '_' . basename($relativePath);
                        $imagePath = '/uploads/images';
                        $destinationPath = public_path($imagePath . '/' . $uniqueName);

                        // Ensure destination directory exists
                        $destinationDir = dirname($destinationPath);
                        if (!File::exists($destinationDir)) {
                            File::makeDirectory($destinationDir, 0755, true);
                        }

                        File::copy($file->getPathname(), $destinationPath);

                        $localFilePath = $imagePath . '/' . $uniqueName;
                        $localFiles[] = $localFilePath;

                        $uploadData[] = [
                            'file_path' => $destinationPath,
                            'github_path' => 'project-files/' . $relativePath,
                            'original_name' => basename($relativePath),
                            'local_path' => $localFilePath,
                            'relative_path' => $relativePath
                        ];
                    }
                }

                Log::info("Project ZIP file processed successfully", [
                    'zip_name' => $zipFile->getClientOriginalName(),
                    'files_extracted' => count($uploadData)
                ]);
            } else {
                Log::error("Failed to open project ZIP file: " . $zipFile->getClientOriginalName());
            }

            // Clean up temp files
            if (File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        } catch (Exception $e) {
            Log::error("Error processing project ZIP file: " . $e->getMessage());

            // Clean up on error
            if (isset($tempDir) && File::exists($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }

        return [
            'upload_data' => $uploadData,
            'local_files' => $localFiles
        ];
    }

    private function isSystemFile($filename)
    {
        $systemFiles = ['.DS_Store', 'Thumbs.db', '.gitkeep', '.htaccess', '.git'];
        $hiddenFiles = substr($filename, 0, 1) === '.' && !in_array($filename, ['.gitignore', '.env.example']);

        return in_array($filename, $systemFiles) || $hiddenFiles;
    }

    private function extractRepoNameFromUrl($githubUrl)
    {
        // Handle various GitHub URL formats
        $patterns = [
            '/github\.com\/[^\/]+\/([^\/\.]+)(?:\.git)?(?:\/.*)?$/',
            '/github\.com\/[^\/]+\/([^\/]+)\/.*$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $githubUrl, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private function generateBranchName($project)
    {
        $backlogId = $project->backlog->id ?? 'backlog';
        $userId = $project->user->id ?? 'user';
        $userName = $project->user->first_name ?? 'user' . $userId;

        // Clean the username for branch naming
        $cleanUserName = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($userName));

        // Create branch name: feature/backlog-{id}-{username}-{timestamp}
        $timestamp = now()->format('Ymd-His');
        return "feature/backlog-{$backlogId}-{$cleanUserName}-{$timestamp}";
    }

    public function viewProject(Request $request)
    {
        $id = $request->id;
        $records = BacklogProject::with(['Backlog', 'User'])->withCount([
            'votes as total_votes' // Get total count of votes
        ])->where('id', $id)
            ->first();
        return response()->json(['status' => 200, 'records' => $records]);
    }

    public function getProject(Request $request)
    {
        $id = Auth()->user()->id;
        $url_backlog_id = $request->url_backlog_id;
        $projectType = $request->project_type ?? 'mine'; // 'mine' or 'other'

        if ($request->ajax() && $request->has('project_type')) {
            $query = BacklogProject::with(['User', 'Backlog']);

            if ($projectType === 'mine') {
                $query->where(function ($q) use ($id) {
                    $q->where('backlog_projects.uploaded_by', $id)
                        ->whereIn('backlog_projects.status', ['0', '1', '3']);
                });
            } else {
                $query->where(function ($q) use ($id) {
                    $q->where('backlog_projects.uploaded_by', '!=', $id)
                        ->whereIn('backlog_projects.status', ['1', '3']);
                });
            }

            $query->withCount([
                'votes as is_voted' => function ($q) {
                    $q->where('user_id', auth()->id());
                },
                'votes as total_votes'
            ]);

            if ($url_backlog_id) {
                $query->where('backlog_id', $url_backlog_id);
            }

            $filters = json_decode($request->filters, true) ?? [];
            $query = $this->recordFilter($query, $filters, $id);

            return DataTables::of($query)
                ->addColumn('status_badge', function ($row) {
                    $statusClass = '';
                    $statusText = '';
                    switch ($row->status) {
                        case '0':
                            $statusClass = 'bg-secondary';
                            $statusText = 'Pending';
                            break;
                        case '1':
                            $statusClass = 'bg-primary';
                            $statusText = 'Approved';
                            break;
                        case '2':
                            $statusClass = 'bg-danger';
                            $statusText = 'Rejected';
                            break;
                        case '3':
                            $statusClass = 'bg-success';
                            $statusText = 'Completed';
                            break;
                    }
                    return '<span class="badge ' . $statusClass . '">' . $statusText . '</span>';
                })
                ->addColumn('user_name', function ($row) {
                    return $row->user->first_name . ' ' . $row->user->last_name;
                })
                ->addColumn('backlog_id', function ($row) {
                    return $row->backlog->id;
                })
                ->addColumn('votes_display', function ($row) {
                    return '<span class="view-voter-list" id="' . $row->id . '" title="View Project List" style="cursor: pointer; background-color: green; color: #fff; padding: .2rem .4rem; border-radius: 50px; font-weight: bold; text-decoration: underline;">' . $row->total_votes . '</span>';
                })
                ->addColumn('git_url_link', function ($row) {
                    return '<a href="' . $row->git_url . '" target="_blank">' . $row->git_url . '</a>';
                })
                ->addColumn('formatted_date', function ($row) {
                    return $row->created_at ? $row->created_at->format('d M Y') : 'N/A';
                })
                ->addColumn('actions', function ($row) use ($id) {
                    $actions = '<div class="dropdown">
                        <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Actions
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item view-project" id="' . $row->id . '">View</a></li>';

                    if ($row->uploaded_by != $id && $row->status != '3') {
                        if ($row->is_voted == 0) {
                            $actions .= '<li><a class="dropdown-item add-vote" id="' . $row->id . '">Vote</a></li>';
                        } else {
                            $actions .= '<li><a class="dropdown-item">Voted</a></li>';
                        }
                    }

                    $actions .= '<li><a data-bs-toggle="offcanvas" href="#timeline" role="button" aria-controls="timeline" class="dropdown-item view-history-log">View Log</a></li>
                        </ul>
                    </div>';

                    return $actions;
                })
                ->rawColumns(['status_badge', 'votes_display', 'git_url_link', 'actions'])
                ->make(true);
        }

        // Non-AJAX response for statistics
        $totalBackLogs = BacklogProject::where('uploaded_by', $id)->count();
        $pendingBackLogs = BacklogProject::where('uploaded_by', $id)->where('status', '0')->count();
        $approvedBackLogs = BacklogProject::where('uploaded_by', $id)->where('status', '1')->count();
        $rejectedBackLogs = BacklogProject::where('uploaded_by', $id)->where('status', '2')->count();

        $data = [
            'total_project' => $totalBackLogs,
            'pending_peoject' => $pendingBackLogs,
            'rejected_peoject' => $rejectedBackLogs,
            'approved_peoject' => $approvedBackLogs
        ];

        return response()->json(['status' => 200, 'data' => $data]);
    }

    public function recordFilter($records, $filters, $id)
    {
        $hasFilters = false; // Track if any filters are applied

        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            if ($filters['sort']['column'] == 'uploaded_by') {
                $records->join('users', 'backlog_projects.uploaded_by', '=', 'users.id')
                    ->orderBy('users.first_name', $filters['sort']['order']);
            } else {
                $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            }
            $hasFilters = true;
        }

        // Apply status filters
        if (isset($filters['status'])) {
            $records->where('status', $filters['status']);
            $hasFilters = true;
        }

        // Apply date range filters
        if (!empty($filters['date_range']['start']) && !empty($filters['date_range']['end'])) {
            $startDate = $filters['date_range']['start'] . ' 00:00:00';
            $endDate = $filters['date_range']['end'] . ' 23:59:59';
            $records->whereBetween('created_at', [$startDate, $endDate]);
            $hasFilters = true;
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $records->where(function ($query) use ($search) {
                $query->where('backlog_projects.title', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.id', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.backlog_id', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.git_url', 'LIKE', "%$search%")
                    ->orWhere('backlog_projects.created_at', 'LIKE', "%$search%")
                    ->orWhereHas('User', function ($query) use ($search) {
                        $query->where('first_name', 'LIKE', "%$search%")
                            ->orWhere('last_name', 'LIKE', "%$search%");
                    })
                    ->orWhereRaw("(SELECT COUNT(*) FROM votes WHERE backlog_projects.id = votes.project_id) LIKE '$search'");
            });
            $hasFilters = true;
        }

        if (!empty($filters['showDeletedBacklogs'])) {
            $records = BacklogProject::with(['User', 'Backlog'])
                ->where(function ($query) use ($id) {
                    $query->where('backlog_projects.uploaded_by', $id);
                })
                ->where('backlog_projects.status', '2')
                ->withCount([
                    'votes as is_voted' => function ($query) {
                        $query->where('user_id', auth()->id());
                    },
                    'votes as total_votes'
                ]);
        }

        return $records;
    }

    public function submitVote(Request $request)
    {
        try {
            $user_id = Auth::user()->id;
            $project_id = $request->project_id;
            $vote_type = $request->vote_type;
            $vote_mode = $request->vote_mode;
            $comment = $request->comment;

            $vote = new Vote;
            $vote->comment = $comment;
            $vote->vote_mode = $vote_mode;
            $vote->vote_type = $vote_type;
            $vote->user_id = $user_id;
            $vote->project_id = $project_id;
            $vote->save();
            logAction(
                LogConstants::VOTED, // Action type
                LogConstants::VOTE,
                $vote->id, // Entity ID (the backlog's ID)
                "Vote cast on the project." // Description of the action
            );
            return response()->json(['status' => 200, 'message' => 'Vote Submitted Successfully']);
        } catch (Exception $e) {
            return response()->json(['status' => 500, 'message' => 'Something went wrong', 'error' => $e->getMessage()]);
        }
    }

    public function viewVoterList(Request $request)
    {
        $project_id = $request->project_id;
        $records = Vote::leftJoin('users', function ($join) {
            $join->on('votes.user_id', '=', 'users.id')
                ->where('votes.vote_mode', '!=', 'anonymous'); // Exclude anonymous votes from join
        })
            ->where('votes.project_id', $project_id)
            ->select('votes.*', 'users.first_name as user_name', 'users.last_name as last_name') // Select user name only if not anonymous
            ->get();

        return response()->json(['status' => 200, 'records' => $records]);
    }

    public function rejectProject(Request $request)
    {
        $projectId = $request->id;
        $userId = Auth()->user()->id;

        // Debug: Log the request data
        Log::info('Reject project request', [
            'project_id' => $projectId,
            'user_id' => $userId,
            'request_data' => $request->all()
        ]);

        // Find the project
        $project = BacklogProject::find($projectId);

        if (!$project) {
            return response()->json([
                'status' => 404,
                'message' => 'Project not found'
            ]);
        }

        // Debug: Log project details
        Log::info('Project found', [
            'project_id' => $project->id,
            'uploaded_by' => $project->uploaded_by,
            'status' => $project->status,
            'current_user' => $userId
        ]);

        // Check if project is in a rejectable status
        if (!in_array($project->status, ['0', '1'])) {
            return response()->json([
                'status' => 400,
                'message' => 'This project cannot be rejected in its current status'
            ]);
        }

        // Update the project status to rejected
        $project->status = '2';
        $project->save();

        // Log the action
        logAction(
            LogConstants::Rejected,
            LogConstants::PROJECT,
            $project->id,
            "Project id '{$project->id}' was rejected by user."
        );

        // Get updated counts for the user
        $totalPendingProjects = BacklogProject::where('uploaded_by', $userId)->where('status', '0')->count();
        $totalProjects = BacklogProject::where('uploaded_by', $userId)->count();

        return response()->json([
            'status' => 200,
            'message' => 'Project rejected successfully',
            'project_id' => $projectId,
            'pending_count' => $totalPendingProjects,
            'total_count' => $totalProjects,
            'action' => 'rejected'
        ]);
    }
}
