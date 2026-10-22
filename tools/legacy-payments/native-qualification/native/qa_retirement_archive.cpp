// Protected original archive only. No command/SQL interface or source mutation.
#include "authority_bridge.cpp"
#include "qa_retirement.hpp"
namespace paymenter_qa {
namespace {
class ProtectedQaArchive:public QaRetirementArchive {
    const std::shared_ptr<PrivateDirectory> dir_;const pid_t process_=getpid();
    std::unique_ptr<File> original_;
    void current()const{guard(getpid()==process_,"retirement-archive-process");dir_->unchanged();}
public:
    explicit ProtectedQaArchive(std::shared_ptr<PrivateDirectory> dir):dir_(dir){}
    void retain(const std::string& raw)override {
        current();guard(!original_ && !dir_->exists("qa.retirement.original"),"retirement-archive-reuse");
        dir_->create_retirement_archive(raw);original_.reset(new File(dir_->read("qa.retirement.original",65536)));
        guard(original_->raw==raw,"retirement-archive-write");current();
    }
    std::string reload()override {
        current();guard(bool(original_),"retirement-archive-missing");auto fresh=dir_->read("qa.retirement.original",65536);
        guard(same_file(*original_,fresh) && hash(original_->raw)==hash(fresh.raw),"retirement-archive-changed");current();return fresh.raw;
    }
};
}
}
