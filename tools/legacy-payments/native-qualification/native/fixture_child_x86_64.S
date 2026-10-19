/* Linux x86-64, no dynamic loader/libc. Filter setup precedes argv access. */
.text
.globl _start
.type _start,@function
_start:
    mov %rsp,%r12
    call fixture_install_filter
    test %eax,%eax
    jnz .Lsetup_failed
    mov (%r12),%rdi
    lea 8(%r12),%rsi
    call fixture_child_main
    mov %eax,%edi
    jmp .Lexit
.Lsetup_failed:
    mov $126,%edi
.Lexit:
    mov $60,%eax
    syscall
    ud2
.size _start,.-_start

.globl fixture_syscall6
.type fixture_syscall6,@function
fixture_syscall6:
    mov %rdi,%rax
    mov %rsi,%rdi
    mov %rdx,%rsi
    mov %rcx,%rdx
    mov %r8,%r10
    mov %r9,%r8
    mov 8(%rsp),%r9
    syscall
    ret
.size fixture_syscall6,.-fixture_syscall6
.section .note.GNU-stack,"",@progbits
