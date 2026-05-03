import 'dart:async';
import 'package:file_picker/file_picker.dart' as fp;
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'services/dashboard_api.dart';
import 'offense_screen.dart';
import 'community_service_screen.dart';
import 'service_history_screen.dart';
import 'alert_screen.dart';
import 'profile_screen.dart';
import 'package:identitrack_app/src/screens/services/offense_api.dart';
import 'login_screen.dart';
import 'shared_bottom_nav.dart';

class DashboardScreen extends StatefulWidget {
  final String studentId;
  final String studentName; // fallback name from login

  const DashboardScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final _api = DashboardApi();
  Timer? _refreshTimer;

  bool _loading = true;
  String? _error;

  late String _studentName;
  int _totalOffense = 0;
  int _minorOffense = 0;
  int _majorOffense = 0;
  int _unseenOffensesCount = 0;
  int _seenAlertsCount = 0;
  int _lastMinorOffenseCount = 0;
  double _communityHours = 0;
  String _accountMode = 'FULL_ACCESS';
  String _accountMessage = 'Account access is normal.';
  String _lastHearingPopupKey = '';
  String _lastRestrictionPopupKey = '';
  HearingNotice? _hearingNotice;
  LatestPunishment? _latestPunishment;
  bool _isPunishmentExpanded = false;
  String? _dismissedMessageText;
  String? _dismissedPunishmentId;
  List<UnseenAppeal> _unseenAppeals = [];
  bool _isSubmitting = false;
  bool _activeServiceSession = false;
  bool _dismissedProbationBanner = false;
  final Set<int> _locallyAcceptedCaseIds = {};

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  @override
  void initState() {
    super.initState();
    _studentName = widget.studentName.trim().isEmpty
        ? 'Student'
        : widget.studentName.trim();
    
    // Load initial prefs before first load to prevent double snackbars
    SharedPreferences.getInstance().then((prefs) {
      if (mounted) {
        _seenAlertsCount = prefs.getInt('seen_alerts_count') ?? 0;
        _lastMinorOffenseCount = prefs.getInt('last_minor_offense_count') ?? 0;
        // Restore locally accepted case IDs to prevent re-showing appeal window
        final accepted = prefs.getStringList('locally_accepted_case_ids') ?? [];
        // Restore dismissed punishment card ID
        final dismissedId = prefs.getString('dismissed_punishment_card');
        setState(() {
          _locallyAcceptedCaseIds.addAll(accepted.map((e) => int.tryParse(e) ?? -1).where((e) => e > 0));
          if (dismissedId != null) _dismissedPunishmentId = dismissedId;
        });
      }
    });

    _load();
    _refreshTimer = Timer.periodic(const Duration(seconds: 10), (_) {
      if (mounted && !_loading) {
        _load(silent: true);
      }
    });
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  String _greeting() {
    final hour = DateTime.now().hour;
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
  }

  bool _isLogoutOnlyMode() {
    return _accountMode == 'PROBATION_FREEZE' ||
        _accountMode == 'PERMANENT_FREEZE_LOGIN_LOGOUT' ||
        _accountMode == 'WARNING_FREEZE_LOGOUT_ONLY';
  }

  bool _isForcedFreezeMode() {
    return _accountMode == 'PERMANENT_FREEZE_LOGIN_LOGOUT' ||
        _accountMode == 'WARNING_FREEZE_LOGOUT_ONLY';
  }

  void _showRestrictedMessage() {
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(SnackBar(content: Text(_accountMessage)));
  }

  String _formatHours(double hours) {
    if (hours <= 0) return '0h';
    final totalSeconds = (hours * 3600).round();
    final h = totalSeconds ~/ 3600;
    final m = (totalSeconds % 3600) ~/ 60;
    final s = totalSeconds % 60;
    return '${h}h ${m}m ${s}s';
  }

  Future<void> _logout() async {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) => const Center(
        child: CircularProgressIndicator(color: Colors.white),
      ),
    );

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('student_id');
    await prefs.remove('student_name');
    await prefs.remove('otp_token');

    await Future.delayed(const Duration(milliseconds: 600));

    if (!mounted) return;

    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (route) => false,
    );
  }

  void _showFreezePrompt(LatestPunishment punishment) {
    if (!mounted) return;

    final popupKey = '${punishment.caseId}-${punishment.resolvedAt}-${_accountMode}';
    if (_lastRestrictionPopupKey == popupKey) return;
    _lastRestrictionPopupKey = popupKey;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      showDialog<void>(
        context: context,
        barrierDismissible: false,
        builder: (dialogContext) {
          return AlertDialog(
            title: const Text('Account Frozen'),
            content: Text(_accountMessage),
            actions: [
              TextButton(
                onPressed: () {
                  Navigator.of(dialogContext).pop();
                  _logout();
                },
                child: const Text('Logout now'),
              ),
            ],
          );
        },
      );
    });
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }

    try {
      final summary = await _api.getSummary(studentId: widget.studentId);
      if (!mounted) return;

      setState(() {
        _studentName = summary.studentName.trim().isEmpty
            ? _studentName
            : summary.studentName.trim();
        _totalOffense = summary.totalOffense;
        _minorOffense = summary.minorOffense;
        _majorOffense = summary.majorOffense;
        _communityHours = summary.communityServiceHours;
        _accountMode = summary.accountMode;
        _accountMessage = summary.accountMessage;
        _hearingNotice = summary.hearingNotice;
        _latestPunishment = summary.latestPunishment;
        _unseenAppeals = summary.unseenAppeals;
        
        if (summary.minorOffense == 2 && _lastMinorOffenseCount < 2 && summary.unseenOffensesCount > 0) {
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) {
              ScaffoldMessenger.of(context)
                ..clearSnackBars()
                ..showSnackBar(
                  SnackBar(
                    content: const Text('Your guardian has been notified regarding your 2nd Minor Offense.'),
                    backgroundColor: Colors.orange.shade800,
                    duration: const Duration(seconds: 5),
                  ),
                );
            }
          });
        } else if (summary.unseenOffensesCount > _unseenOffensesCount && summary.unseenOffensesCount > 0) {
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) {
              ScaffoldMessenger.of(context)
                ..clearSnackBars()
                ..showSnackBar(
                  const SnackBar(
                    content: Text('New Offense Recorded. Please check your Offenses tab.'),
                    backgroundColor: Colors.red,
                    duration: Duration(seconds: 4),
                  ),
                );
            }
          });
        }
        
        _unseenOffensesCount = summary.unseenOffensesCount;
        _lastMinorOffenseCount = summary.minorOffense;
        _activeServiceSession = summary.activeServiceSession;
        _loading = false;
      });

      final prefs = await SharedPreferences.getInstance();
      await prefs.setBool('active_service_session', summary.activeServiceSession);
      await prefs.setBool('recent_service_logout', summary.recentServiceLogout);
      await prefs.setString('active_service_session_id', summary.activeServiceSessionId);
      await prefs.setString('recent_service_logout_id', summary.recentServiceLogoutId);
      await prefs.setInt('unseen_offenses_count', summary.unseenOffensesCount);
      await prefs.setInt('total_alerts_count', summary.totalAlertsCount);
      await prefs.setInt('last_minor_offense_count', summary.minorOffense);
      
      _dismissedMessageText = prefs.getString('dismissed_account_message');
      _dismissedPunishmentId = prefs.getString('dismissed_punishment_card');

      if (_accountMode == 'AUTO_LOGOUT_FREEZE') {
        if (!mounted) return;
        ScaffoldMessenger.of(context)
          ..clearSnackBars()
          ..showSnackBar(
            const SnackBar(
              content: Text('Account is fully frozen. Logging out...'),
            ),
          );
        await _logout();
        return;
      }

      if (_isForcedFreezeMode() && summary.latestPunishment != null) {
        _showFreezePrompt(summary.latestPunishment!);
      }

      final hearing = summary.hearingNotice;
      if (hearing != null && hearing.popup) {
        final popupKey =
            '${hearing.caseId}-${hearing.hearingDate}-${hearing.hearingTime}';
        if (_lastHearingPopupKey != popupKey) {
          _lastHearingPopupKey = popupKey;
          _showHearingPopup(hearing);
        }
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
        _loading = false;
      });
    }
  }

  String _categoryLabel(int category) {
    switch (category) {
      case 1:
        return 'Category 1 - Probation';
      case 2:
        return 'Category 2 - Formative Intervention';
      case 3:
        return 'Category 3 - Non-readmission';
      case 4:
        return 'Category 4 - Exclusion';
      case 5:
        return 'Category 5 - Expulsion';
      default:
        return 'Category $category';
    }
  }

  Future<void> _submitLatestAppeal() async {
    final punishment = _latestPunishment;
    if (punishment == null || !punishment.canAppeal) {
      _showRestrictedMessage();
      return;
    }

    final reasonCtrl = TextEditingController();
    String? error;
    bool submitting = false;
    fp.PlatformFile? selectedFile;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) {
        return StatefulBuilder(
          builder: (context, setStateDialog) => AlertDialog(
            title: const Text('Appeal UPCC Decision'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Explain why you are appealing ${_categoryLabel(punishment.category)}.',
                  style: TextStyle(color: Colors.grey.shade700),
                ),
                const SizedBox(height: 12),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.orange.shade50,
                    border: Border.all(color: Colors.orange.shade300),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.warning_amber_rounded, color: Colors.orange.shade900, size: 28),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'WARNING: Your appeal letter must include the printed name and signature of both the student and the parents/guardian.',
                          style: TextStyle(color: Colors.orange.shade900, fontSize: 13, fontWeight: FontWeight.w600),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: reasonCtrl,
                  minLines: 3,
                  maxLines: 6,
                  decoration: const InputDecoration(
                    hintText: 'Enter your appeal reason...',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 14),
                ElevatedButton.icon(
                  onPressed: () async {
                    final result = await fp.FilePicker.pickFiles(
                      type: fp.FileType.custom,
                      allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png'],
                      withData: true,
                    );
                    if (result != null) {
                      setStateDialog(() => selectedFile = result.files.first);
                    }
                  },
                  icon: const Icon(Icons.attach_file),
                  label: const Text('Attach Photo/PDF'),
                ),
                if (selectedFile != null) ...[
                  const SizedBox(height: 6),
                  Text(
                    'Selected: ${selectedFile!.name}',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                    ),
                  ),
                ],
                if (error != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    error!,
                    style: const TextStyle(
                      color: Colors.red,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ],
            ),
            actions: [
              TextButton(
                onPressed: submitting
                    ? null
                    : () => Navigator.of(dialogContext).pop(),
                child: const Text('Cancel'),
              ),
              ElevatedButton(
                onPressed: submitting
                    ? null
                    : () async {
                        final reason = reasonCtrl.text.trim();
                        if (reason.length < 10) {
                          setStateDialog(
                            () => error =
                                'Please provide at least 10 characters.',
                          );
                          return;
                        }

                        setStateDialog(() {
                          submitting = true;
                          error = null;
                        });

                        try {
                          await _api.submitUpccCaseAppeal(
                            studentId: widget.studentId,
                            caseId: punishment.caseId,
                            reason: reason,
                            filePath: selectedFile?.path,
                            fileBytes: selectedFile?.bytes,
                            fileName: selectedFile?.name,
                          );
                          if (context.mounted) {
                            Navigator.of(dialogContext).pop();
                            ScaffoldMessenger.of(context)
                              ..clearSnackBars()
                              ..showSnackBar(
                                const SnackBar(
                                  content: Text(
                                    'UPCC appeal submitted. Admin will review it.',
                                  ),
                                ),
                              );
                            await _load(silent: true);
                          }
                        } catch (e) {
                          setStateDialog(() {
                            submitting = false;
                            error = e.toString().replaceFirst(
                              'Exception: ',
                              '',
                            );
                          });
                        }
                      },
                child: Text(submitting ? 'Submitting...' : 'Submit Appeal'),
              ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _acceptUPCCDecision() async {
    final punishment = _latestPunishment;
    if (punishment == null || !punishment.canAppeal) {
      _showRestrictedMessage();
      return;
    }

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Accept Punishment'),
        content: Text('Are you sure you want to accept this UPCC decision?\n\nIf you accept this, this will be your final punishment:\n\n${punishment.decisionText}\n\nYou will waive your right to appeal.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.green.shade700),
            child: const Text('Accept Punishment'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      try {
        setState(() => _isSubmitting = true);
        await OffenseApi().acceptUpccCase(
          studentId: widget.studentId,
          caseId: punishment.caseId,
        );
        // Persist acceptance locally so the appeal window never re-appears
        // even if the server returns stale data (e.g. after a DB migration).
        final prefs = await SharedPreferences.getInstance();
        _locallyAcceptedCaseIds.add(punishment.caseId);
        await prefs.setStringList(
          'locally_accepted_case_ids',
          _locallyAcceptedCaseIds.map((e) => e.toString()).toList(),
        );
        if (mounted) {
          ScaffoldMessenger.of(context)
            ..clearSnackBars()
            ..showSnackBar(
              const SnackBar(content: Text('You have accepted the UPCC decision. Your punishment is now final.')),
            );
          await _load(silent: true);
          if (mounted) {
            _onBottomNavTap(2);
          }
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Error: $e')),
          );
        }
      } finally {
        if (mounted) {
          setState(() => _isSubmitting = false);
        }
      }
    }
  }

  Widget _latestPunishmentCard() {
    final punishment = _latestPunishment;
    if (punishment == null) {
      return const SizedBox.shrink();
    }

    // Override can_appeal if the student already accepted locally.
    // This guards against stale server data (e.g. after a DB migration).
    // Also: if community service hours exist, the student already accepted
    // the Category 2 decision — so the appeal window must be closed even
    // after a fresh APK install (SharedPreferences wiped).
    final bool studentHasAcceptedViaService =
        punishment.category == 2 && _communityHours > 0;
    final bool effectiveCanAppeal =
        punishment.canAppeal &&
        !_locallyAcceptedCaseIds.contains(punishment.caseId) &&
        !studentHasAcceptedViaService;

    final details = punishment.details;
    final interventions = details['interventions'] is List
        ? (details['interventions'] as List).map((e) => e.toString()).toList()
        : <String>[];
    final semester = (details['semester'] ?? '').toString().trim();
    final probationTerms = (details['probation_terms'] ?? '').toString().trim();
    final serviceHours = (details['service_hours'] ?? '').toString().trim();
    final suspendIfViolated = details['suspend_if_violated'] == true;
    final appealStatus = punishment.appealStatus.trim().toUpperCase();

    String detailText = punishment.decisionText.trim().isEmpty
        ? 'The UPCC decision has been recorded.'
        : punishment.decisionText.trim();
    if (punishment.category == 1 && semester.isNotEmpty) {
      detailText = '$detailText\nSemester: $semester';
      if (probationTerms.isNotEmpty) {
        detailText = '$detailText\nProbation terms: $probationTerms academic terms';
      }
    } else if (punishment.category == 2) {
      final parts = <String>[];
      if (interventions.isNotEmpty) {
        parts.add('Interventions: ${interventions.join(', ')}');
      }
      if (serviceHours.isNotEmpty) {
        parts.add('Service hours: $serviceHours');
      }
      if (parts.isNotEmpty) {
        detailText = '$detailText\n${parts.join('\n')}';
      }
    } else if (punishment.category >= 3 && punishment.category <= 5) {
      detailText =
          '$detailText\nThe account will update after the appeal window closes.';
    }

    final accent = punishment.category == 1
        ? const Color(0xFF1565C0)
        : punishment.category == 2
        ? const Color(0xFF6A1B9A)
        : punishment.category == 3
        ? const Color(0xFFEF6C00)
        : punishment.category == 4
        ? const Color(0xFFB71C1C)
        : const Color(0xFF4A148C);

    return InkWell(
      onTap: effectiveCanAppeal
          ? () {
              setState(() {
                _isPunishmentExpanded = !_isPunishmentExpanded;
              });
            }
          : null,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: accent.withValues(alpha: 0.22)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.05),
              blurRadius: 18,
              offset: const Offset(0, 10),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(Icons.gavel_rounded, color: accent),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _activeServiceSession && punishment.category == 2
                          ? 'Disciplinary Program'
                          : _categoryLabel(punishment.category),
                      style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        color: blueDark,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      _activeServiceSession && punishment.category == 2
                          ? 'Serving Formative Intervention'
                          : (effectiveCanAppeal
                              ? 'Appeal window active'
                              : 'Decision Accepted & Finalized'),
                      style: TextStyle(
                        color: accent,
                        fontWeight: FontWeight.w700,
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ),
              // Always show dismiss X — student can close the card at any time
              IconButton(
                onPressed: () async {
                  final prefs = await SharedPreferences.getInstance();
                  await prefs.setString('dismissed_punishment_card', punishment.caseId.toString());
                  setState(() {
                    _dismissedPunishmentId = punishment.caseId.toString();
                  });
                },
                icon: Icon(Icons.close_rounded, color: Colors.grey.shade400),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            detailText,
            style: TextStyle(
              color: Colors.grey.shade800,
              height: 1.4,
              fontWeight: FontWeight.w600,
            ),
          ),
          if (appealStatus.isNotEmpty) ...[
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFEFF6FF),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFBFDBFE)),
              ),
              child: Text(
                appealStatus == 'PENDING' || appealStatus == 'REVIEWING'
                    ? 'Appeal status: waiting for admin review.'
                    : 'Appeal status: ${appealStatus.toLowerCase()}.',
                style: TextStyle(
                  color: Colors.grey.shade800,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
          if (punishment.category == 1 && suspendIfViolated) ...[
            const SizedBox(height: 8),
            Text(
              'If the student commits another major offense during probation, the next term may result in suspension or non-readmission.',
              style: TextStyle(
                color: Colors.grey.shade700,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
          if (effectiveCanAppeal) ...[
            const SizedBox(height: 10),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFFFF8E1),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFFFE082)),
              ),
              child: Text(
                'The decision is still within the appeal window. Your account state will update automatically.',
                style: TextStyle(
                  color: Colors.grey.shade800,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            const SizedBox(height: 10),
            if (_isPunishmentExpanded) ...[
              Row(
                children: [
                  Expanded(
                    child: ElevatedButton.icon(
                      onPressed: _acceptUPCCDecision,
                      icon: const Icon(Icons.check_rounded),
                      label: const Text('Accept'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.green.shade700,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        textStyle: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: ElevatedButton.icon(
                      onPressed: _submitLatestAppeal,
                      icon: const Icon(Icons.gavel_rounded),
                      label: const Text('Appeal'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: accent,
                        foregroundColor: Colors.white,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        textStyle: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                    ),
                  ),
                ],
              ),
            ] else ...[
              Center(
                child: Text(
                  'Tap to view actions',
                  style: TextStyle(
                    color: Colors.grey.shade600,
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ],
          if (_unseenAppeals.isNotEmpty) ...[
            ..._unseenAppeals.map((ua) {
              if (ua.status == 'APPROVED') {
                return _approvedAppealBanner(ua);
              } else {
                return _rejectedAppealBanner(ua);
              }
            }),
          ],
        ],
      ),
    ),
  );
}

  Widget _approvedAppealBanner(UnseenAppeal ua) {
    String recordName = ua.appealKind == 'UPCC_CASE'
        ? 'UPCC Case #${ua.caseId} (Category ${ua.category})'
        : 'Offense #${ua.offenseId}';

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [Colors.green.shade50, Colors.white],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.green.shade200, width: 1.5),
        boxShadow: [
          BoxShadow(
            color: Colors.green.withValues(alpha: 0.1),
            blurRadius: 20,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: Colors.green.shade100,
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(Icons.check_circle_rounded, color: Colors.green, size: 24),
                  ),
                  const SizedBox(width: 12),
                  const Text(
                    'Appeal Approved!',
                    style: TextStyle(
                      fontWeight: FontWeight.w900,
                      color: Colors.green,
                      fontSize: 16,
                      letterSpacing: -0.3,
                    ),
                  ),
                ],
              ),
              InkWell(
                onTap: () => _dismissAppeal(ua.appealId),
                child: Container(
                  padding: const EdgeInsets.all(4),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(Icons.close_rounded, size: 20, color: Colors.grey.shade600),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            'Great news! Your appeal for $recordName was formally approved. The assigned penalty has been voided.',
            style: TextStyle(
              color: Colors.grey.shade800,
              fontWeight: FontWeight.w600,
              fontSize: 13.5,
              height: 1.5,
            ),
          ),
          if (ua.adminResponse.trim().isNotEmpty) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.green.shade100),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.notes_rounded, size: 16, color: Colors.green.shade400),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      ua.adminResponse.trim(),
                      style: TextStyle(
                        color: Colors.grey.shade700,
                        fontSize: 12.5,
                        fontStyle: FontStyle.italic,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _rejectedAppealBanner(UnseenAppeal ua) {
    String recordName = ua.appealKind == 'UPCC_CASE'
        ? 'UPCC Case #${ua.caseId} (Category ${ua.category})'
        : 'Offense #${ua.offenseId}';

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFFEF2F2),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFFECACA), width: 1.5),
        boxShadow: [
          BoxShadow(
            color: Colors.red.withValues(alpha: 0.08),
            blurRadius: 15,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: Colors.red.shade100,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(Icons.warning_rounded, color: Colors.red, size: 24),
                  ),
                  const SizedBox(width: 12),
                  const Text(
                    'Appeal Rejected',
                    style: TextStyle(
                      fontWeight: FontWeight.w900,
                      color: Colors.red,
                      fontSize: 16,
                      letterSpacing: -0.3,
                    ),
                  ),
                ],
              ),
              InkWell(
                onTap: () => _dismissAppeal(ua.appealId),
                child: Container(
                  padding: const EdgeInsets.all(4),
                  decoration: BoxDecoration(
                    color: Colors.red.shade50,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(Icons.close_rounded, size: 20, color: Colors.red.shade400),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            'Your appeal for $recordName was rejected by the administration. The penalty is now final.',
            style: TextStyle(
              color: Colors.red.shade900,
              fontWeight: FontWeight.w600,
              fontSize: 13.5,
              height: 1.5,
            ),
          ),
          if (ua.adminResponse.trim().isNotEmpty) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.red.shade100),
              ),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.feedback_outlined, size: 16, color: Colors.red.shade400),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      ua.adminResponse.trim(),
                      style: TextStyle(
                        color: Colors.grey.shade800,
                        fontSize: 12.5,
                        height: 1.4,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _dismissAppeal(int appealId) async {
    try {
      await _api.acknowledgeAppeal(studentId: widget.studentId, appealId: appealId);
      if (mounted) {
        setState(() {
          _unseenAppeals.removeWhere((e) => e.appealId == appealId);
        });
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Could not dismiss: $e')));
    }
  }

  Widget _activeCaseCard(HearingNotice notice) {
    return InkWell(
      onTap: () => _onBottomNavTap(1),
      borderRadius: BorderRadius.circular(16),
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: const Color(0xFFFFF3E0),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xFFFFCC80)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.05),
              blurRadius: 18,
              offset: const Offset(0, 10),
            ),
          ],
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: const Color(0xFFE65100).withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Icon(Icons.warning_amber_rounded, color: Color(0xFFE65100)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    notice.title.isNotEmpty ? notice.title : 'Active UPCC Case',
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      color: Color(0xFFE65100),
                      fontSize: 15,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    notice.message,
                    style: TextStyle(
                      color: Colors.orange.shade900,
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                      height: 1.4,
                    ),
                  ),
                  if (!notice.hasExplanation) ...[
                    const SizedBox(height: 8),
                    const Text(
                      'Tap to view details and submit explanation',
                      style: TextStyle(
                        color: Color(0xFFE65100),
                        fontWeight: FontWeight.w800,
                        fontSize: 11,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _showHearingPopup(HearingNotice hearing) async {
    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(hearing.title.isEmpty ? 'Hearing Reminder' : hearing.title),
        content: Text(hearing.message),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('OK'),
          ),
        ],
      ),
    );
  }

  Widget _statCard({
    required String title,
    required String value,
    Color? color,
    VoidCallback? onTap,
  }) {
    final c = color ?? blueDark;
    final card = Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.grey.shade200),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 18,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            value,
            style: TextStyle(
              fontSize: 28,
              fontWeight: FontWeight.w900,
              color: c,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            title,
            style: TextStyle(
              color: Colors.grey.shade700,
              fontWeight: FontWeight.w600,
              fontSize: 14,
            ),
          ),
        ],
      ),
    );

    return onTap == null
        ? card
        : InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(16),
            child: card,
          );
  }

  Widget _actionButton({
    required String title,
    required String subtitle,
    required IconData icon,
    required Color color,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.grey.shade200),
        ),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(icon, color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                      fontWeight: FontWeight.w900,
                      color: blueDark,
                      fontSize: 16,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    style: TextStyle(
                      color: Colors.grey.shade600,
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_right_rounded, color: Colors.grey),
          ],
        ),
      ),
    );
  }

  void _onBottomNavTap(int i) async {
    // Removed restriction to allow navigation to Offenses, Service, and Alerts even during probation.
    if (i == 1) {
      // Offenses -> go to OffenseScreen
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => OffenseScreen(
            studentId: widget.studentId,
            studentName: _studentName,
          ),
        ),
      );
      return;
    }

    if (i == 2) {
      // Service -> go to ServiceHistoryScreen
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => ServiceHistoryScreen(
            studentId: widget.studentId,
            studentName: _studentName,
          ),
        ),
      );
      return;
    }

    if (i == 3) {
      // Alerts -> go to AlertsScreen
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => AlertsScreen(
            studentId: widget.studentId,
            studentName: _studentName,
          ),
        ),
      );
      return;
    }

    if (i == 4) {
      // Profile -> go to ProfileScreen
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => ProfileScreen(
            studentId: widget.studentId,
            studentName: _studentName,
          ),
        ),
      );
      return;
    }
  }


  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: blue,
      appBar: AppBar(
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text(
          'Dashboard',
          style: TextStyle(fontWeight: FontWeight.w900),
        ),
      ),
      body: SafeArea(
        child: Stack(
          children: [
            Container(
              width: double.infinity,
              decoration: const BoxDecoration(
                color: Color(0xFFF5F6FB),
                borderRadius: BorderRadius.only(
                  topLeft: Radius.circular(28),
                  topRight: Radius.circular(28),
                ),
              ),
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : (_error != null)
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(18),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(_error!, textAlign: TextAlign.center),
                            const SizedBox(height: 10),
                            ElevatedButton(
                              onPressed: _load,
                              child: const Text('Retry'),
                            ),
                          ],
                        ),
                      ),
                    )
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(18, 22, 18, 100),
                      children: [
                        Text(
                          '${_greeting()}, $_studentName',
                          style: const TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w900,
                            color: blueDark,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Student ID: ${widget.studentId}',
                          style: TextStyle(
                            color: Colors.grey.shade700,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        const SizedBox(height: 18),

                        if (_accountMessage != _dismissedMessageText && _accountMode != 'PROBATION_FREEZE') ...[
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.only(left: 12, top: 12, bottom: 12, right: 4),
                            decoration: BoxDecoration(
                              color: _accountMode == 'FULL_ACCESS'
                                  ? const Color(0xFFE8F5E9)
                                  : const Color(0xFFFFF8E1),
                              border: Border.all(
                                color: _accountMode == 'FULL_ACCESS'
                                    ? const Color(0xFFA5D6A7)
                                    : const Color(0xFFFFE082),
                              ),
                              borderRadius: BorderRadius.circular(14),
                            ),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Expanded(
                                  child: Padding(
                                    padding: const EdgeInsets.only(top: 4, right: 8),
                                    child: Text(
                                      _accountMessage,
                                      style: const TextStyle(
                                        color: blueDark,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                  ),
                                ),
                                InkWell(
                                  onTap: () async {
                                    final prefs = await SharedPreferences.getInstance();
                                    await prefs.setString('dismissed_account_message', _accountMessage);
                                    setState(() {
                                      _dismissedMessageText = _accountMessage;
                                    });
                                  },
                                  borderRadius: BorderRadius.circular(20),
                                  child: const Padding(
                                    padding: EdgeInsets.all(8.0),
                                    child: Icon(
                                      Icons.close_rounded,
                                      size: 18,
                                      color: blueDark,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],

                        if (_hearingNotice != null) ...[
                          const SizedBox(height: 12),
                          _activeCaseCard(_hearingNotice!),
                        ],

                        if (_latestPunishment != null && _latestPunishment!.caseId.toString() != _dismissedPunishmentId) ...[
                          const SizedBox(height: 12),
                          _latestPunishmentCard(),
                        ],

                        const SizedBox(height: 12),

                        Text(
                          'Discipline Summary',
                          style: TextStyle(
                            color: Colors.grey.shade800,
                            fontWeight: FontWeight.w800,
                            fontSize: 16,
                          ),
                        ),
                        const SizedBox(height: 12),

                        Row(
                          children: [
                            Expanded(
                              child: _statCard(
                                title: 'Total Offenses',
                                value: _totalOffense.toString(),
                                color: blueDark,
                                onTap: () => _onBottomNavTap(1),
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: _statCard(
                                title: 'Minor Offenses',
                                value: _minorOffense.toString(),
                                color: const Color(0xFFE65100),
                                onTap: () => _onBottomNavTap(1),
                              ),
                            ),
                          ],
                        ),

                        const SizedBox(height: 12),

                        Row(
                          children: [
                            Expanded(
                              child: _statCard(
                                title: 'Major Offenses',
                                value: _majorOffense.toString(),
                                color: const Color(0xFFD32F2F),
                                onTap: () => _onBottomNavTap(1),
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: _statCard(
                                title: 'Community Service',
                                value: _formatHours(_communityHours),
                                color: const Color(0xFF2E7D32),
                                onTap: () => _onBottomNavTap(2),
                              ),
                            ),
                          ],
                        ),

                        const SizedBox(height: 24),

                        Text(
                          'Quick Actions',
                          style: TextStyle(
                            color: Colors.grey.shade800,
                            fontWeight: FontWeight.w800,
                            fontSize: 16,
                          ),
                        ),
                        const SizedBox(height: 12),

                        _actionButton(
                          title: 'View Offense History',
                          subtitle: 'See all violations',
                          icon: Icons.description_rounded,
                          color: const Color(0xFF1976D2),
                          onTap: () => _onBottomNavTap(1),
                        ),
                        const SizedBox(height: 10),
                        _actionButton(
                          title: 'Community Service',
                          subtitle: 'Track your hours',
                          icon: Icons.access_time_rounded,
                          color: const Color(0xFF2E7D32),
                          onTap: () => _onBottomNavTap(2),
                        ),

                      ],
                    ),
            ),
            
            // Probation Banner
            if (_accountMode == 'PROBATION_FREEZE' && !_dismissedProbationBanner)
              Positioned(
                bottom: 20,
                left: 18,
                right: 18,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                  decoration: BoxDecoration(
                    color: Colors.black.withValues(alpha: 0.9),
                    borderRadius: BorderRadius.circular(12),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.2),
                        blurRadius: 10,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline_rounded, color: Colors.white, size: 20),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          _accountMessage,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      InkWell(
                        onTap: () => setState(() => _dismissedProbationBanner = true),
                        child: Container(
                          padding: const EdgeInsets.all(4),
                          decoration: BoxDecoration(
                            color: Colors.white.withValues(alpha: 0.1),
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(Icons.close_rounded, color: Colors.white, size: 16),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 0,
        studentId: widget.studentId,
        studentName: _studentName,
      ),
    );
  }
}
