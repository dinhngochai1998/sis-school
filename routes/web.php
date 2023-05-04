<?php

/** @var Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

use Laravel\Lumen\Routing\Router;
use YaangVu\LaravelBase\Helpers\RouterHelper;

$router->get('/', function () use ($router) {
    return env('APP_NAME') . ' - ' . $router->app->version();
});

$router->group(['prefix' => '/', 'middleware' => ['school']], function () use ($router) {
    //TODO SCHOOLS
    $router->get('/schools/current', 'SchoolController@getCurrentSchool');
    RouterHelper::resource($router, '/schools', 'SchoolController');

    //TODO TERMS
    $router->post('/terms/{id}/conclude', 'TermController@concludeTerm');
    $router->post('/terms-classes-copy', 'TermController@copyTermClasses');
    $router->post('/terms-classes-copy-options', 'TermController@copyOptionsTermClasses');
    $router->get('terms/student/{id}', 'TermController@getTermsByStudentId');
    RouterHelper::resource($router, '/terms', 'TermController');

    //TODO GRADES
    $router->get('/schools/{id}/grades', 'GradeController@getViaSchoolId');
    RouterHelper::resource($router, '/grades', 'GradeController');

    //TODO DIVISION
    //    $router->get('/schools/code/{code}/divisions','DivisionController@getViaSchoolCode');
    $router->get('/schools/{id}/divisions', 'DivisionController@getViaSchoolId');
    RouterHelper::resource($router, '/divisions', 'DivisionController');

    //TODO COURSE
    RouterHelper::resource($router, '/courses', 'CourseController');

    //TODO SUBJECT
    RouterHelper::resource($router, '/subjects', 'SubjectController');
    $router->post('/subjects/sync-type', 'SubjectController@syncType');

    //TODO SUBJECT RULE
    RouterHelper::resource($router, '/subject-rules', 'SubjectRuleController');

    //TODO GRADUATION CATEGORIES
    $router->get('/user-academic-plan/{uuId}/{programId}', 'GraduationCategorySubjectController@getUserAcademicPlan');
    RouterHelper::resource($router, '/graduation-categories', 'GraduationCategoryController');

    //TODO STUDENT INFORMATION CREDIT SUMMARY
    $router->get('/student-information/credit-summary', 'GraduationCategoryController@creditSummary');
    $router->get('/student-information/credit-summary/export', 'GraduationCategoryController@exportCreditSummary');

    //TODO CLASSES
    $router->get('/classes/{id}/calendar', 'ClassController@getCalendarViaClassId');
    $router->post('/classes/{id}/assign-students', 'ClassController@assignStudents');
    $router->post('/classes/{id}/assign-teachers', 'ClassController@assignTeachers');
    $router->post('/classes/{id}/un-assign-students', 'ClassController@unAssignStudents');
    $router->get('/classes/{id}/assignable-students', 'ClassController@getAssignableStudents');
    $router->get('/classes/{id}/students', 'ClassController@getStudentViaClassId');
    $router->get('/classes/in-process/{uuid}/user', 'ClassController@getClassesInProcess');
    $router->post('/classes/{id}/copy', 'ClassController@copyClass');
    $router->post('/classes/{id}/conclude', 'ClassController@concludeClass');
    $router->get('/classes/current-user', 'ClassController@getListClassForCurrentUser');
    $router->post('/classes/{id}/assignment-status', 'ClassController@updateStatusAssign');
    $router->get('/classes/template/enroll-student', 'ClassController@downloadEnrollStudentTemplate');
    $router->post('/classes/{id}/import/enroll-student', 'ClassController@importEnrollStudent');
    $router->post('/classes/student/{id}/assignment-status',
                  'ClassController@updateStatusAssignFromClassesViaStudentId');
    $router->post('/classes/student/{id}/un-assign-student', 'ClassController@unAssignStudentFromClassesViaStudentId');
    $router->get('/classes/{id}/term', 'ClassController@getClassByTermId');
    $router->get('/classes/{id}/number-student', 'ClassController@getNumBerStudentByClassId');

    RouterHelper::resource($router, '/classes', 'ClassController');


    //TODO PROGRAMS
    RouterHelper::resource($router, '/programs', 'ProgramController');

    //TODO LMS
    $router->get('/lms/{id}/courses', 'LMSController@getCoursesViaLmsId');
    $router->get('/lms', 'LMSController@index');
    $router->get('/lms/{id}/zones', 'LMSController@getZonesViaId');

    //TODO GRADE SCALE
    RouterHelper::resource($router, '/grade-scales', 'GradeScaleController');

    //TODO CALENDAR
    $router->post('/calendars/school-event', 'CalendarController@addSchoolEvent');
    $router->put('/calendars/{id}/school-event', 'CalendarController@updateSchoolEvent');
    $router->put('/calendars/{id}/single', 'CalendarController@updateSingleEvent');
    $router->post('/calendars/class-schedule', 'CalendarController@addClassSchedule');
    $router->put('/calendars/{id}/class-schedule', 'CalendarController@updateClassSchedule');
    $router->patch('/calendars/{id}/delete', 'CalendarController@destroy');
    $router->get('/calendars/current-user', 'CalendarController@getAllWithoutPaginateForCurrentUser');
    $router->get('/calendars/sync-terms', 'CalendarController@syncTerms');
    $router->delete('/calendars/{id}/video-conference', 'CalendarController@deleteCalendarsTypeVideoConference');
    $router->get('/calendars/video-conference/{zoomMeetingId}', 'CalendarController@getCalendarZoomMeetingViaZoomMeetingId');
    $router->get('/calendars/dashboard', 'CalendarController@getAllWithoutPaginateForDashboard');
    $router->get('/calendars/current-user/dashboard', 'CalendarController@getAllWithoutPaginateForCurrentUserDashboard');
    $router->post('/calendars/{id}/cancel-video-conference', 'CalendarController@cancelCalendarsTypeVideoConference');
    $router->delete('/calendars/{id}/canceled', 'CalendarController@deleteCalendarsTypeCanceled');
    RouterHelper::resource($router, '/calendars', 'CalendarController');

    //TODO CLASS ACTIVITY
    $router->post('/class-activities/import/class/{id}', 'ClassActivityController@importActivityScore');
    $router->get('/class-activities/template/class/{id}', 'ClassActivityController@getTemplateActivityScore');
    $router->get('/class-activities/class/{id}', 'ClassActivityController@getViaClassId');
    $router->get('/class-activities/export/class/{id}', 'ClassActivityController@exportLmsClassActivity');

    //TODO CLASS SIS
    $router->post('/class-activities/activities/class/{classId}', 'ClassActivitySisController@addActivity');
    $router->delete('/class-activities/activities/class/{classId}', 'ClassActivitySisController@deleteActivity');
    $router->put('/class-activities/activities/class/{classId}', 'ClassActivitySisController@updateActivity');
    $router->put('/score/activities/class-activities/class/{classId}',
                 'ClassActivitySisController@updateScoreToClassActivity');
    $router->post('/class-activities/sis/setup-parameter/class/{classId}', 'ClassActivitySisController@setUpParameter');

    $router->get('/class-activities/export/sis/class/{classId}', 'ClassActivityController@exportSisClassActivity');
    $router->post('/class-activities/lms/setup-parameter/class/{classId}', 'ClassActivityLmsController@setupParameter');
    $router->get('/class-activities/lms/class/{classId}', 'ClassActivityLmsController@getViaClassId');
    $router->put('/class-activities/score/lms/class/{classId}', 'ClassActivityLmsController@updateActivityScore');
    $router->post('/class-activities/activity/lms/class/{classId}', 'ClassActivityLmsController@addActivity');
    $router->delete('/class-activities/lms/setup-parameter/class/{classId}',
                    'ClassActivityLmsController@removeAllParameters');

    //TODO ATTENDANCE
    $router->get('/attendances/percent-student', 'AttendanceController@getAttendancePercentStudent');
    $router->get('/attendances/percent-status', 'AttendanceController@getAttendancePercentStatus');
    $router->get('/attendances/calendars/{id}', 'AttendanceController@getAllViaCalendarId');
    $router->get('/attendances', 'AttendanceController@index');
    $router->post('/attendances', 'AttendanceController@store');
    $router->get('/attendances/user/{userId}/class/{classId}', 'AttendanceController@getViaUserIdAndClassId');
    $router->get('/attendances/user/{id}', 'AttendanceController@getViaStudentId');
    // Report
    $router->group(['prefix' => '/reports'], function () use ($router) {
        // Attendance
        $router->get('/attendance/present-percentage', 'ReportController@getAttendancePercentage');
        $router->get('/attendance/present-summary', 'ReportController@getAttendanceSummary');
        $router->get('/attendance/overview-percentage', 'ReportController@getChartPipeAttendance');
        $router->get('/attendance/student/{id}', 'ReportController@getStudentForAttendanceReport');
        $router->get('/attendance/present-top', 'ReportController@getAttendanceTopPresent');
        $router->get('/attendance/daily-attendance', 'ReportController@getStatusDetailsDailyAttendance');
        $router->get('/attendance/by-class', 'ReportController@getStatusDetailAttendancesByClass');

        // Score
        // $router->get('/score/top-gpa', 'ReportController@getTopGpa');
        $router->get('/score/summary', 'ReportController@getScoreSummary');
        $router->get('/score/grade-letter', 'ReportController@getScoreGradeLetter');
        $router->get('/score/course-grade', 'ReportController@getScoreCourseGrade');

        // GPAgpa
        $router->get('/gpa/summary', 'ReportController@getGpaSummary');
        $router->get('/gpa/top-gpa-students', 'ReportController@getTopGpaStudents');
        $router->get('/gpa/top-gpa', 'ReportController@getTopGpa');

        // TODO TASK MANAGEMENT
        $router->get('/task-managements/status', 'ReportController@getStatusChartTaskManagement');
        $router->get('/task-managements/timeliness', 'ReportController@getTimelinessChartTaskManagement');

        // TODO COMMUNICATION LOG
        $router->get('/communication-logs', 'ReportController@getCommunicationLog');
    });

    // TODO CALCULATOR
    $router->post('/calculator/gpa-score', 'CalculatorController@calculateGpaScore');
    $router->post('/calculator/cpa-rank', 'CalculatorController@calculateCpaRank');

    // TODO CLASS ACTIVITY CATEGORY
    $router->get('/class-activity-categories/class/{id}', 'ClassActivityCategoryController@getViaClassId');
    $router->post('/class-activity-categories/class/{id}', 'ClassActivityCategoryController@insertBatch');

    // TODO ACTIVITY CATEGORY
    $router->post('updateOrInsert/activity-categories', 'ActivityCategoryController@upsertActivityCategories');
    $router->get('/activity-categories/current-schools', 'ActivityCategoryController@getParameterCurrentSchools');
    RouterHelper::resource($router, '/activity-categories', 'ActivityCategoryController');

    // TODO DASHBOARD
    $router->group(['prefix' => '/dashboards'], function () use ($router) {
        $router->get('/synthetic', 'DashboardController@getSyntheticForDashBoard');
        $router->get('/percentage-gender', 'DashboardController@getPercentageGender');
        $router->get('/percentage-grade', 'DashboardController@getPercentageGrade');
        $router->get('/classes/students-overview', 'DashboardController@getStudentsOverview');
        $router->get('/classes', 'DashboardController@getClassesOverview');
        $router->get('/cpa/summary', 'DashboardController@getCpaSummary');
    });

    // TODO SRI SMI
    $router->group(['prefix' => '/sri-smi'], function () use ($router) {
        $router->post('/import', 'SriSmiController@importSriSmi');
        $router->get('/assessment/user/{userId}/grade/{gradeId}', 'SriSmiController@getSriSmiAssessment');
        $router->get('/report', 'SriSmiController@getSriSmiReport');
        $router->get('/user/{userId}/grade', 'SriSmiController@getGradeSriSmiByUserId');
        $router->get('/assessment/user/{userId}', 'SriSmiController@getALlSriSmiAssessment');
    });

    $router->group(['prefix' => '/physical-performance-measures'], function () use ($router) {
        $router->get("/template", "PhysicalPerformanceMeasuresController@getTemplatePhysicalPerformanceMeasures");
        $router->post("/import", "PhysicalPerformanceMeasuresController@importPhysicalPerformanceMeasures");
    });

    // TODO api  ACT
    $router->group(['prefix' => '/act'], function () use ($router) {
        $router->get('/template', 'ActController@getTemplateACTScore');
        $router->post('/import', 'ActController@importAct');
        $router->get('/user/{id}', 'ActController@getUserDetailAct');
    });

    // TODO api SBAC

    $router->group(['prefix' => '/sbac'], function () use ($router) {
        $router->get('/template', 'SbacController@getTemplateSbacScore');
        $router->post('/import', 'SbacController@importSbac');
        $router->get('user/{id}', 'SbacController@getUserDetailSbac');
    });


    $router->group(['prefix' => '/sat'], function () use ($router) {
        $router->get("/template", "SatController@getTemplateSat");
        $router->post("/import", "SatController@importSat");
    });

    $router->group(['prefix' => '/user'], function () use ($router) {
        // SAT
        $router->get('/{id}/report/sat', 'UserController@getUserDetailSat');

        //Physical performance
        $router->get('/{id}/report/physical-performance-measures', 'UserController@getUserDetailPhysicalPerformance');

        //
        //Ielts
        $router->get('/{id}/individual/ielts', 'UserController@getUserDetailIelts');
        //credit summary
        $router->get('/{termId}/teacher-assignment', 'UserController@getAllPrimaryTeacherAssignmentByTermId');
        $router->get('/{classId}/student', 'UserController@getStudentByClassId');

    });

    // TODO api Communication Log
    $router->group(['prefix' => '/communication-log'], function () use ($router) {
        RouterHelper::resource($router, '/', 'CommunicationController');
        $router->get('', 'CommunicationController@index');

    });

    // TODO IELTS REPORT
    $router->group(['prefix' => '/ielts'], function () use ($router) {
        $router->get('/template', 'IeltsController@getTemplateIelts');
        $router->post('/import', 'IeltsController@importIelts');
        $router->get('/test-name', 'IeltsController@groupTestName');
        $router->get('/overall', 'IeltsController@getIeltsOverall');
        $router->get('/top-bottom', 'IeltsController@getIeltsTopAndBottom');
        $router->get('/component-score', 'IeltsController@getIeltsComponentScore');
        $router->get('/chart-individual/user/{id}', 'IeltsController@chartIndividualIelts');
    });

    // TODO TOEFL REPORT
    $router->group(['prefix' => '/toefl'], function () use ($router) {
        $router->get('/template', 'ToeflController@getTemplateToefl');
        $router->post('/import', 'ToeflController@importToefl');
        $router->get('/total-score', 'ToeflController@getToeflTotalScore');
        $router->get('/component-score', 'ToeflController@getToeflComponentScore');
        $router->get('/top-bottom', 'ToeflController@getToeflTopAndBottom');
        $router->get('/individual/user/{userId}', 'ToeflController@getToefl');
        $router->get('/test-name', 'ToeflController@groupTestName');

    });

    // TODO GRADUATION
    $router->group(['prefix' => '/graduation'], function () use ($router) {
        $router->put('/{userId}/update', 'GraduationController@upsertGraduation');
        $router->get('/{userId}', 'GraduationController@getGraduationByUserId');
    });

    // TODO SURVEY
    $router->group(['prefix' => '/survey'], function () use ($router) {
        RouterHelper::resource($router, '/', 'SurveyController');
    });

    // TODO SURVEY REPORT
    $router->group(['prefix' => '/report-surveys'], function () use ($router) {
        $router->get('/summarize', 'SurveyReportController@surveySummarizeReport');
        $router->get('/question', 'SurveyReportController@reportQuestion');
        $router->get('{id}/individual', 'SurveyReportController@reportSurveyIndividual');
        $router->get('{id}/export', 'SurveyReportController@exportSurvey');
        RouterHelper::resource($router, '/', 'SurveyReportController');
    });

    // TODO ACTIVITY CLASS LMS
    RouterHelper::resource($router, 'activity-class-lms', 'ActivityClassLmsController');

    // TODO ROOM SETTING
    $router->post('/zoom-setting', 'ZoomSettingController@setupZoomSetting');
    $router->get('/zoom-setting', 'ZoomSettingController@getAllZoomSetting');
    $router->get('/zoom-setting/{id}', 'ZoomSettingController@show');

    // TODO ZOOM MEETING
    $router->post('/zoom-meeting/scheduled-meeting', 'ZoomMeetingController@addScheduledMeeting');
    $router->put('/zoom-meeting/{id}/scheduled-meeting', 'ZoomMeetingController@updateScheduledMeeting');
    $router->delete('/zoom-meeting/{id}', 'ZoomMeetingController@deleteScheduledMeeting');
    $router->get('/zoom-meeting', 'ZoomMeetingController@getAllZoomMeeting');
    $router->get('/zoom-meeting/{id}', 'ZoomMeetingController@getZoomMeetingById');
    $router->get('/zoom-meeting/{id}/participant', 'ZoomMeetingController@getParticipantByZoomMeeting');
    $router->post('/zoom-meeting/recurring-meeting', 'ZoomMeetingController@addRecurringMeeting');
    $router->put('/zoom-meeting/{id}/recurring-meeting', 'ZoomMeetingController@updateRecurringMeeting');
    $router->post('/zoom-meeting/{id}/generate-link', 'ZoomMeetingController@generateLinkZoomMeetingViaMeetingId');
    $router->put('/zoom-meeting/{id}', 'ZoomMeetingController@updateZoomMeeting');

    // TODO ZOOM HOST
    $router->get('/zoom-host', 'ZoomHostController@getAllZoomHost');

    // TODO ZOOM MEETING
    $router->get('report-zoom-meeting/{id}/attendance-log', 'AttendanceLogController@getAttendanceLogByZoomMeetingId');
    $router->put('attendance-log/{id}', 'AttendanceLogController@update');
    $router->get('report-zoom-meeting/{id}/report-statistics', 'AttendanceLogController@getReportStatistics');
    $router->get('report-zoom-meeting/{id}/calendar/date', 'AttendanceLogController@getDateByZoomMeetingId');
    $router->put('attendance-logs/zoom-meeting/{zoomMeetingId}',
                 'AttendanceLogController@updateAttendanceLogsViaZoomMeetingId');
    $router->get('attendance-logs/group-calendar/{group}', 'AttendanceLogController@getAttendanceLogsViaGroupCalendar');

    //TODO TASK MANAGEMENT
    $router->group(['prefix' => '/task-managements'], function () use ($router) {
        $router->get('', 'TaskManagementController@getAllListTaskManagement');
        $router->post('/sub-task', 'SubTaskController@createSubTask');
        $router->get('/owner', 'TaskManagementController@getOwnerTaskManagement');
    });
    $router->post('/task-individual', 'SubTaskController@createTaskIndividual');
    $router->patch('/task-individual/{id}', 'SubTaskController@editTaskIndividual');
    RouterHelper::resource($router, '/main-task', 'MainTaskController');
    RouterHelper::resource($router, '/sub-task', 'SubTaskController');
    RouterHelper::resource($router, '/task-status', 'TaskStatusController');
    RouterHelper::resource($router, '/task-comment', 'TaskCommentController');

    //TODO CHAT PLATFORM
    $router->post('/chat-platform/supporters', 'ChatSupporterController@updateSupporter');
    $router->get('/chat-platform/supporters', 'ChatSupporterController@getAll');

    //TODO SMS TEMPLATE
    $router->get('/report-sms/{id}', 'SmsParticipantController@reportSms');
    RouterHelper::resource($router, '/sms-setting', 'SmsSettingController');
    RouterHelper::resource($router, '/sms-participant', 'SmsParticipantController');
    RouterHelper::resource($router, '/sms', 'SmsController');

    //TODO STATES
    RouterHelper::resource($router, '/states', 'StateController');

    //TODO SUBJECT TYPE
    RouterHelper::resource($router, '/subject-types', 'SubjectTypeController');
});


// TODO PUBLIC API SURVEY
$router->group(['prefix' => '/public/survey'], function () use ($router) {
    $router->get('/{surveyId}/{hash}', 'SurveyController@getDetailSurvey');
    RouterHelper::resource($router, '/', 'SurveyReportController');

});


$router->get('/debug-sentry', function () {
    throw new Exception('My first Sentry error!');
});

$router->get("/schools/code/{code}", "SchoolController@showByCode");

// $router->post('/public/zoom/webhook','ZoomMeetingController@hookDataZoomMeeting');

$router->post('/public/sms/webhook', 'SmsParticipantController@hookStatusSms');
