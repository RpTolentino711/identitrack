import 'package:flutter/material.dart';
import 'package:file_picker/file_picker.dart' as fp;
import 'services/offense_api.dart';

class OffenseDetailScreen extends StatefulWidget {
  final String studentName;
  final String studentId;
  final String program;
  final int yearLevel;

  final OffenseItem offense;

  const OffenseDetailScreen({
    super.key,
    required this.studentName,
    required this.studentId,
    required this.program,
    required this.yearLevel,
    required this.offense,
  });

  @override
  State<OffenseDetailScreen> createState() => _OffenseDetailScreenState();
}

class _OffenseDetailScreenState extends State<OffenseDetailScreen> {
  bool _isExpanded = false;
  bool _isSubmittingExp = false;
  final _explanationCtrl = TextEditingController();
  fp.PlatformFile? _selectedExpFile;

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  Color _levelColor(String level) {
    if (widget.offense.isBundle) return const Color(0xFFC62828);
    switch (level.toUpperCase()) {
      case 'MAJOR':
        return const Color(0xFFC62828);
      case 'MINOR':
      default:
        return const Color(0xFF2E7D32);
    }
  }

  IconData _levelIcon(String level) {
    if (widget.offense.isBundle) return Icons.gavel_rounded;
    switch (level.toUpperCase()) {
      case 'MAJOR':
        return Icons.warning_amber_rounded;
      case 'MINOR':
      default:
        return Icons.report_gmailerrorred_rounded;
    }
  }

  bool _canAppeal() {
    final level = widget.offense.level.toUpperCase();
    final status = widget.offense.status.toUpperCase();
    if (status == 'UNDER_APPEAL') return false;
    if (status == 'VOID') return false;
    if (widget.offense.acknowledgedAt != null) return false;
    return level == 'MAJOR';
  }

  bool _isAcknowledged() {
    return widget.offense.acknowledgedAt != null;
  }

  Future<void> _acceptOffense(BuildContext context) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Accept Offense'),
        content: const Text(
          'Are you sure you want to accept and acknowledge this offense? You will waive your right to appeal.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Accept Offense'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      try {
        await OffenseApi().acceptOffense(
          studentId: widget.studentId,
          offenseId: widget.offense.offenseId,
        );
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Offense acknowledged.')),
          );
          Navigator.of(context).pop();
        }
      } catch (e) {
        if (context.mounted) {
          ScaffoldMessenger.of(
            context,
          ).showSnackBar(SnackBar(content: Text('Error: $e')));
        }
      }
    }
  }

  Future<void> _submitAppeal(BuildContext context) async {
    final reasonCtrl = TextEditingController();
    String? error;
    bool submitting = false;

    fp.PlatformFile? selectedFile;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) {
        return StatefulBuilder(
          builder: (context, setStateDialog) => AlertDialog(
            title: const Text('Submit Appeal'),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Tell UPCC/Admin why you are appealing this decision.',
                    style: TextStyle(color: Colors.grey.shade700),
                  ),
                  const SizedBox(height: 10),
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
                            style: TextStyle(
                              color: Colors.orange.shade900,
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
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
                          await OffenseApi().submitAppeal(
                            studentId: widget.studentId,
                            offenseId: widget.offense.offenseId,
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
                                    'Appeal submitted. UPCC/Admin will review it.',
                                  ),
                                ),
                              );
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

  Padding _infoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 140,
            child: Text(
              label,
              style: TextStyle(
                color: Colors.grey.shade700,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                color: blueDark,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _submitExplanation() async {
    final text = _explanationCtrl.text.trim();
    if (text.isEmpty && _selectedExpFile == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please provide an explanation or attach a file.')),
      );
      return;
    }

    if (widget.offense.upccCaseId == null) return;

    setState(() => _isSubmittingExp = true);

    try {
      await OffenseApi().submitExplanation(
        studentId: widget.studentId,
        caseId: widget.offense.upccCaseId!,
        explanation: text,
        filePath: _selectedExpFile?.path,
        fileBytes: _selectedExpFile?.bytes,
        fileName: _selectedExpFile?.name,
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Explanation submitted successfully.')),
        );
        Navigator.of(context).pop();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to submit: $e')),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _isSubmittingExp = false);
      }
    }
  }

  Widget _explanationSection() {
    final o = widget.offense;
    if (o.upccCaseId == null) return const SizedBox.shrink();

    // If already submitted
    if (o.explanationAt != null) {
      return Container(
        margin: const EdgeInsets.only(top: 14),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: Colors.grey.shade200),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Your Submitted Explanation',
              style: TextStyle(
                fontWeight: FontWeight.w900,
                color: blueDark,
                fontSize: 15,
              ),
            ),
            const SizedBox(height: 10),
            if (o.explanationText != null && o.explanationText!.isNotEmpty)
              Text(
                o.explanationText!,
                style: TextStyle(color: Colors.grey.shade800, fontWeight: FontWeight.w600),
              ),
            if (o.explanationImage != null || o.explanationPdf != null) ...[
              const SizedBox(height: 10),
              Row(
                children: [
                  const Icon(Icons.attach_file_rounded, size: 16, color: blue),
                  const SizedBox(width: 4),
                  const Text(
                    'Attachment uploaded',
                    style: TextStyle(color: blue, fontWeight: FontWeight.w700, fontSize: 13),
                  ),
                ],
              ),
            ],
            const SizedBox(height: 8),
            Text(
              'Submitted on ${o.explanationAt}',
              style: TextStyle(color: Colors.grey.shade500, fontSize: 11, fontWeight: FontWeight.w700),
            ),
          ],
        ),
      );
    }

    // Form to submit
    return Container(
      margin: const EdgeInsets.only(top: 14),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF8E1),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFFFE082)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.edit_note_rounded, color: Color(0xFFE65100)),
              SizedBox(width: 8),
              Text(
                'Submit Explanation',
                style: TextStyle(
                  fontWeight: FontWeight.w900,
                  color: Color(0xFFE65100),
                  fontSize: 15,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          const Text(
            'The UPCC panel requires your explanation before the hearing can proceed. You may provide a written statement and attach supporting documents (Photo/PDF).',
            style: TextStyle(
              color: Color(0xFFBF360C),
              fontWeight: FontWeight.w600,
              fontSize: 12.5,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 14),
          TextField(
            controller: _explanationCtrl,
            maxLines: 4,
            decoration: InputDecoration(
              hintText: 'Enter your explanation...',
              fillColor: Colors.white,
              filled: true,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
            ),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _isSubmittingExp
                ? null
                : () async {
                    final result = await fp.FilePicker.pickFiles(
                      type: fp.FileType.custom,
                      allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png'],
                      withData: true,
                    );
                    if (result != null) {
                      setState(() => _selectedExpFile = result.files.first);
                    }
                  },
            icon: const Icon(Icons.attach_file),
            label: Text(_selectedExpFile == null ? 'Attach Photo/PDF' : 'Change Attachment'),
          ),
          if (_selectedExpFile != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'Selected: ${_selectedExpFile!.name}',
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12, color: blueDark),
              ),
            ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: _isSubmittingExp ? null : _submitExplanation,
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFFE65100),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 14),
              ),
              child: Text(
                _isSubmittingExp ? 'Submitting...' : 'Submit Evidence',
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _hideOffense(BuildContext context) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Remove from view?'),
        content: Text(widget.offense.isBundle
            ? 'This will hide this Major decision card and its 3 minor offenses from your active list.'
            : 'This will hide the offense from your active list, but it will remain in your history.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            child: const Text('Hide'),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    try {
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (_) => const Center(child: CircularProgressIndicator()),
      );
      await OffenseApi().hideOffense(
        studentId: widget.studentId,
        offenseId: widget.offense.offenseId,
      );
      if (!context.mounted) return;
      Navigator.of(context).pop(); // pop dialog
      Navigator.of(context).pop(); // pop screen
    } catch (e) {
      if (!context.mounted) return;
      Navigator.of(context).pop(); // pop dialog
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('Failed to hide offense: $e')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final level = widget.offense.level.toUpperCase();
    final levelColor = _levelColor(level);

    final displayStudentName = widget.studentName.trim().isEmpty
        ? 'Student'
        : widget.studentName.trim();
    final displayProgram = widget.program.trim().isEmpty
        ? '—'
        : widget.program.trim();

    return Scaffold(
      backgroundColor: blue,
      appBar: AppBar(
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text(
          'Offense Details',
          style: TextStyle(fontWeight: FontWeight.w900),
        ),
        actions: [
          IconButton(
            tooltip: 'Hide Offense',
            icon: const Icon(
              Icons.delete_outline_rounded,
              color: Colors.redAccent,
            ),
            onPressed: () => _hideOffense(context),
          ),
        ],
      ),
      body: SafeArea(
        child: Container(
          width: double.infinity,
          decoration: const BoxDecoration(
            color: Color(0xFFF5F6FB),
            borderRadius: BorderRadius.only(
              topLeft: Radius.circular(28),
              topRight: Radius.circular(28),
            ),
          ),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
            children: [
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: Colors.grey.shade200),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 48,
                      height: 48,
                      decoration: BoxDecoration(
                        color: levelColor.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: Icon(_levelIcon(level), color: levelColor),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            widget.offense.title.trim(),
                            style: const TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w900,
                              color: blueDark,
                            ),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            level,
                            style: TextStyle(
                              color: levelColor,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 14),

              InkWell(
                onTap: _canAppeal()
                    ? () {
                        setState(() {
                          _isExpanded = !_isExpanded;
                        });
                      }
                    : null,
                borderRadius: BorderRadius.circular(18),
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(18),
                    border: Border.all(color: Colors.grey.shade200),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Offense Information',
                        style: TextStyle(
                          fontWeight: FontWeight.w900,
                          color: blueDark,
                          fontSize: 15,
                        ),
                      ),
                      const SizedBox(height: 10),
                      _infoRow('Date Committed', widget.offense.dateCommitted),
                      _infoRow('Status', widget.offense.status),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 14),

              if (widget.offense.description.trim().isNotEmpty) ...[
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(18),
                    border: Border.all(color: Colors.grey.shade200),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Description',
                        style: TextStyle(
                          fontWeight: FontWeight.w900,
                          color: blueDark,
                          fontSize: 15,
                        ),
                      ),
                      const SizedBox(height: 10),
                      Text(
                        widget.offense.description.trim(),
                        style: TextStyle(
                          color: Colors.grey.shade800,
                          fontWeight: FontWeight.w600,
                          height: 1.35,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
              ],

              _explanationSection(),

              if (_isAcknowledged()) ...[
                const SizedBox(height: 14),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F5E9),
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: const Color(0xFFA5D6A7)),
                  ),
                  child: Row(
                    children: [
                      const Icon(
                        Icons.check_circle_rounded,
                        color: Color(0xFF2E7D32),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'You have acknowledged this offense on ${widget.offense.acknowledgedAt}.',
                          style: const TextStyle(
                            color: Color(0xFF1B5E20),
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ] else if (_canAppeal()) ...[
                if (_isExpanded) ...[
                  Row(
                    children: [
                      Expanded(
                        child: ElevatedButton.icon(
                          onPressed: () => _acceptOffense(context),
                          icon: const Icon(Icons.check_rounded),
                          label: const Text('Accept'),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: Colors.green.shade700,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            textStyle: const TextStyle(
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: ElevatedButton.icon(
                          onPressed: () => _submitAppeal(context),
                          icon: const Icon(Icons.gavel_rounded),
                          label: const Text('Appeal'),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: blueDark,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            textStyle: const TextStyle(
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'You may accept this decision or submit an appeal for UPCC/Admin review.',
                    style: TextStyle(
                      color: Colors.grey.shade700,
                      fontWeight: FontWeight.w600,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ] else ...[
                  Center(
                    child: Text(
                      'Tap Offense Information to view actions',
                      style: TextStyle(
                        color: Colors.grey.shade600,
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ],
              ] else if (widget.offense.level.toUpperCase() != 'MINOR') ...[
                Text(
                  'This record is already under appeal or cannot be appealed.',
                  style: TextStyle(
                    color: Colors.grey.shade700,
                    fontWeight: FontWeight.w600,
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
