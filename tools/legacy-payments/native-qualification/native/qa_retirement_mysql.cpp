// Fixed native SQL backend only. No CLI, guard issuer, module unload or table
// creator: callers must supply actual resource custody and writer retirement.
// Compile alone; the outer issuer supplies its protected credential channel.
#include "qa_retirement_archive.cpp"
#include <mysql/mysql.h>
#include "qa_retirement.hpp"
namespace paymenter_qa {
namespace {
class NativeQaRetirementDatabase:public QaRetirementDatabase {
    std::unique_ptr<MYSQL,decltype(&mysql_close)> db_;
    const QaRetirementGuard& guard_;
    const std::string database_,socket_;
    const QaRetirementRegistry registry_;
    const Clock::time_point end_;
    const pid_t process_=getpid();
    const std::thread::id thread_=std::this_thread::get_id();
    std::string connection_;
    bool started_=false,locked_=false,poisoned_=false,dropped_=false,closed_=false;
    static std::string names() {return "('paymenter_qa_business_v1','paymenter_qa_journal_v1')";}
    void current()const {
        retirement_require(getpid()==process_ && std::this_thread::get_id()==thread_,"retirement-process-or-thread");
        retirement_require(Clock::now()<end_,"retirement-deadline");guard_.current();
        retirement_require(Clock::now()<end_,"retirement-deadline");
        retirement_require(!poisoned_ && !closed_,"retirement-channel-held");
    }
    void query(const std::string& sql) {
        current();
        if(mysql_real_query(db_.get(),sql.data(),sql.size())!=0){poisoned_=true;throw QaRetirementError("retirement-query-incomplete");}
        current();
    }
    ReadRows select(const std::string& sql,unsigned columns,size_t maximum) {
        query(sql);
        std::unique_ptr<MYSQL_RES,decltype(&mysql_free_result)> result(mysql_store_result(db_.get()),mysql_free_result);
        retirement_require(bool(result) && mysql_num_fields(result.get())==columns &&
            mysql_num_rows(result.get())<=maximum,"retirement-result-bound");
        ReadRows rows;MYSQL_ROW row;size_t total=0;
        while((row=mysql_fetch_row(result.get()))!=nullptr) {
            auto lengths=mysql_fetch_lengths(result.get());retirement_require(lengths!=nullptr,"retirement-result");
            std::vector<std::string> values;
            for(unsigned i=0;i<columns;++i) {
                retirement_require(row[i] && lengths[i]<=4096,"retirement-cell");total+=lengths[i];
                retirement_require(total<=65536,"retirement-result-bound");values.emplace_back(row[i],lengths[i]);
            }
            rows.push_back(values);
        }
        retirement_require(mysql_errno(db_.get())==0,"retirement-fetch");current();return rows;
    }
    void session(bool autocommit,bool allow_transaction=false) {
        auto rows=select("SELECT CONNECTION_ID(),@@session.autocommit,DATABASE(),@@socket",4,1);
        retirement_require(rows.size()==1 && reader_connection(rows[0][0]) &&
            rows[0][0]==std::to_string(mysql_thread_id(db_.get())) && (connection_.empty() || rows[0][0]==connection_) &&
            rows[0][1]==(autocommit?"1":"0") && rows[0][2]==database_ && rows[0][3]==socket_,"retirement-session");
        retirement_require((allow_transaction || !(db_->server_status&1)) && bool(db_->server_status&2)==autocommit,"retirement-active-transaction");
        connection_=rows[0][0];
    }
    void metadata() {
        auto tables=select("SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names()+" ORDER BY TABLE_NAME",3,2);
        retirement_require(tables==ReadRows{{"paymenter_qa_business_v1","InnoDB","BASE TABLE"},{"paymenter_qa_journal_v1","InnoDB","BASE TABLE"}},"retirement-tables");
        auto triggers=select("SELECT 'present' FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN "+names()+" LIMIT 1",1,1);
        retirement_require(triggers.empty(),"retirement-trigger");
        auto links=select("SELECT 'present' FROM information_schema.KEY_COLUMN_USAGE WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names()+
            " AND REFERENCED_TABLE_NAME IS NOT NULL) OR (REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN "+names()+") LIMIT 1",1,1);
        retirement_require(links.empty(),"retirement-foreign-key");
    }
    void locked() {current();retirement_require(locked_ && !dropped_,"retirement-not-locked");session(false,true);}
public:
    NativeQaRetirementDatabase(const std::string& password,const std::string& database,const std::string& socket,
                              const QaRetirementGuard& guard,const QaRetirementRegistry& registry,Clock::time_point end):
        db_(nullptr,mysql_close),guard_(guard),database_(database),socket_(socket),registry_(registry),end_(end) {
        current();retirement_require(end_-Clock::now()<=std::chrono::seconds(30),"retirement-deadline");
        retirement_require(reader_hex(registry_.run) && !registry_.cases.empty() && registry_.cases.size()<=5,"retirement-registry");
        std::set<std::string> ids;
        for(const auto& c:registry_.cases)retirement_require(c.id.size()==2 && c.id[0]=='F' && c.id[1]>='1' && c.id[1]<='5' &&
            reader_hex(c.facts) && ids.insert(c.id).second,"retirement-registry");
        retirement_require(!database_.empty() && database_.size()<=64 && database_.find_first_not_of("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_")==std::string::npos &&
            !socket_.empty() && socket_[0]=='/' && socket_.size()<108,"retirement-channel");
        db_.reset(mysql_init(nullptr));retirement_require(bool(db_),"retirement-client-init");
        unsigned timeout=2,protocol=MYSQL_PROTOCOL_SOCKET,disabled=0;my_bool reconnect=0;
        for(auto option:{MYSQL_OPT_CONNECT_TIMEOUT,MYSQL_OPT_READ_TIMEOUT,MYSQL_OPT_WRITE_TIMEOUT})
            retirement_require(mysql_options(db_.get(),option,&timeout)==0,"retirement-client-options");
        retirement_require(mysql_options(db_.get(),MYSQL_OPT_PROTOCOL,&protocol)==0 && mysql_options(db_.get(),MYSQL_OPT_LOCAL_INFILE,&disabled)==0 &&
            mysql_options(db_.get(),MYSQL_OPT_RECONNECT,&reconnect)==0,"retirement-client-options");
        current();retirement_require(mysql_real_connect(db_.get(),"localhost","root",password.c_str(),database_.c_str(),0,socket_.c_str(),0)!=nullptr,"retirement-connect");
        session(true);
    }
    ~NativeQaRetirementDatabase() {
        // The inherited socket belongs to the parent. mysql_close would send
        // COM_QUIT into that session; leave its local allocation until exit.
        if(getpid()!=process_)(void)db_.release();
    }
    // An aborted/unknown channel is closed, never reconnected or used for repair.
    // mysql_close releases its own server-side locks/rolls back uncommitted DML.
    void lock_qa_tables()override {
        current();retirement_require(!started_,"retirement-lock-reuse");started_=true;
        session(true);
        query("SET SESSION lock_wait_timeout=2");query("SET SESSION innodb_lock_wait_timeout=2");
        auto limits=select("SELECT @@session.lock_wait_timeout,@@session.innodb_lock_wait_timeout,@@session.innodb_table_locks",3,1);
        retirement_require(limits==ReadRows{{"2","2","1"}},"retirement-lock-settings");
        metadata();
        query("SET SESSION autocommit=0");session(false);
        query("LOCK TABLES paymenter_qa_business_v1 WRITE,paymenter_qa_journal_v1 WRITE");locked_=true;
        metadata();
    }
    std::array<std::string,2> definitions()override {
        locked();std::array<std::string,2> result;
        const std::array<std::string,2> tables{{"paymenter_qa_business_v1","paymenter_qa_journal_v1"}};
        for(size_t i=0;i<2;++i) {
            auto rows=select("SHOW CREATE TABLE "+tables[i],2,1);
            retirement_require(rows.size()==1 && rows[0][0]==tables[i] && !rows[0][1].empty(),"retirement-schema");result[i]=rows[0][1];
        }
        return result;
    }
    ReadRows all_qa_rows()override {
        locked();auto rows=select("SELECT 'business',run_id,case_id,CAST(amount AS CHAR),facts_hash FROM paymenter_qa_business_v1 UNION ALL SELECT 'journal',run_id,case_id,business_case_id,facts_hash FROM paymenter_qa_journal_v1 LIMIT 33",5,33);
        retirement_require(rows.size()<=32,"retirement-row-bound");return rows;
    }
    void erase_case(const std::string& run,const std::string& id,const std::string& facts)override {
        current();retirement_require(reader_hex(run) && reader_hex(facts) && id.size()==2 && id[0]=='F' && id[1]>='1' && id[1]<='5',"retirement-selector");
        bool registered=false;
        for(const auto& c:registry_.cases)if(c.id==id && c.facts==facts)registered=true;
        retirement_require(run==registry_.run && registered,"retirement-unregistered-selector");
        locked();metadata();
        retirement_require(definitions()==registry_.definitions,"retirement-schema-changed");
        const std::string match=" WHERE run_id='"+run+"' AND case_id='"+id+"' AND facts_hash='"+facts+"'";
        query("DELETE FROM paymenter_qa_journal_v1"+match+" AND business_case_id='"+id+"'");
        query("DELETE FROM paymenter_qa_business_v1"+match+" AND amount=0.0100");
        // Explicit table locks survive COMMIT. Never START TRANSACTION: that
        // would release LOCK TABLES. A commit failure is terminal/unknown.
        query("COMMIT AND NO CHAIN NO RELEASE");session(false);
    }
    void drop_empty_qa_tables()override {
        locked();metadata();retirement_require(definitions()==registry_.definitions,"retirement-schema-changed");
        retirement_require(all_qa_rows().empty(),"retirement-drop-nonempty");
        // DROP is implicitly committing and may be partial on this old server.
        // No IF EXISTS, CASCADE, compensation or automatic second attempt.
        query("DROP TABLE paymenter_qa_business_v1,paymenter_qa_journal_v1");dropped_=true;
    }
    bool qa_tables_absent()override {
        current();retirement_require(dropped_,"retirement-drop-not-attempted");
        return select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN "+names()+" ORDER BY TABLE_NAME",1,2).empty();
    }
    void unlock_qa_tables()override {
        retirement_require(getpid()==process_ && std::this_thread::get_id()==thread_,"retirement-process-or-thread");
        if(!db_)return;
        if(poisoned_ || Clock::now()>=end_) {closed_=true;db_.reset();locked_=false;throw QaRetirementError("retirement-channel-closed-held");}
        // Closing rather than issuing more SQL after revocation also retires this
        // connection, but must leave the policy result incomplete.
        try {current();query("ROLLBACK AND NO CHAIN NO RELEASE");query("UNLOCK TABLES");locked_=false;closed_=true;db_.reset();}
        catch(...){closed_=true;db_.reset();locked_=false;throw;}
    }
};
}
}
