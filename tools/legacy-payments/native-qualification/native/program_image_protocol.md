# Native executable reference custody

`program_image.hpp` handles program files only. It does not read credentials,
issue authority, run SQL, load a module or launch a command. Root-native entry
points supply root ownership internally; no new CLI owner/path policy override
is exposed. The standalone launcher uses the same check with its already-bound
owner and executable reference.

A logical executable pathname must be canonical printable ASCII. Its directory
chain is opened component by component with NOFOLLOW; each held directory is
owned by root or the selected owner and cannot be written by group/others. Both
held and named directory device/inode/owner/mode identities are checked again.
Directory timestamps are not frozen: unrelated entry creation does not replace
a directory. Intermediate symlinks are rejected. Directories traversed by a link
containing dot or parent components are also opened, retained and checked before
normalization can remove them. A skipped missing/symlink directory, a file used
as a directory, repeated separators or a trailing slash refuses.

The leaf may be a protected regular program or one owned singly linked symlink.
The exact symlink text is retained. Relative targets are normalized against the
logical parent; the canonical target's directory chain is independently opened
with NOFOLLOW. A target that is another symlink, dangling, writable, not regular,
not executable, wrongly owned or oversized refuses. A positive existing hard-link
count is permitted for the program target and becomes part of its identity.
No vendor file is copied, relinked, renamed or modified to satisfy this guard.

The logical leaf and target are retained with their device, inode, mode, owner,
size, link count and nanosecond mtime/ctime. Linux holds a leaf-symlink descriptor
with O_PATH/NOFOLLOW; macOS uses O_SYMLINK. An unsupported platform refuses an
alias. The resolved regular target descriptor is always opened with NOFOLLOW.
After reads and before use, the held descriptors, named leaves, link text and
both directory chains must still match. Same-target alias replacement and
same-byte target replacement refuse. Observable metadata changes also refuse.
An identical-byte write within the filesystem timestamp quantum can leave every
tracked value unchanged; metadata is not treated as a write-event log. Executable
bytes are additionally hash-bound by native image consumers.

The full bounded reference binds logical path, target path, link text, both file
identities and directory identities. The native File comparison includes this
reference, in addition to its original target stamp and bytes. Root authority,
resource and controller factories select this policy for executable roles;
the exact approved module path continues through the old strict image reader.
The SDK runtime rechecks its retained executable reference as well as its kernel
process and actual loaded module mapping.

`NativeOwnedChild` preserves the approved logical pathname as argv[0]. A leaf
alias requires its previously bound full reference, not just target metadata.
Linux fexecve executes the pinned target descriptor; no PATH lookup or shell is
introduced. Original link count and nanosecond metadata remain part of launch
and later revalidation. Identity changes stop the launch/run; they never grant
permission to signal another process.

The controller retains four protected `capture.image.<role>.original` records
for the actual program references. Each command original binds the corresponding
reference hash along with its actual argv/environment and target image identity.
Authored-boundary fixtures without genuine program references remain fictional;
the root public factory always obtains references from real protected images.

Private approval, registry, diagnostic, schema, stream/archive and control-file
custody is unchanged: one protected regular file, one link, no leaf alias.
The native SDK module image also remains single-link and no-alias. Executable
file/launch checks do not qualify SDK startup, global symbol visibility, actor or
worker behavior, transaction coupling, financial posting or service handover.
